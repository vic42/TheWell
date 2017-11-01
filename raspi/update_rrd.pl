#!/usr/bin/perl

# use strict;
use Time::HiRes qw( usleep gettimeofday tv_interval );
use Storable qw(lock_store lock_retrieve);
use List::Util qw(max);

# storage of ultrasonic values for filtering
my $ustore_name = '/home/vic/filter.store';
my $ustore_size = 6; 	# number of values to low-pass filter (minutes average)

# storage of current values for filtering
my $cstore_name = '/home/vic/current.store';
my $cstore_size = 5; 	# number of values to low-pass filter (minutes maximum)

# Calibration values for VRef and Diode offset
my $v33 = 3.40;		# reference voltage to the ADC (3.3V rail of raspi)
my $offset = .30;	# input schottky diode offset to v24 voltage
my $resistance = 0.88;	# resistance of batteries and cables
my $ultrasonic = 121.7;	# height of ultrasonic sensor above reservoir bottom
# ORIG: 129.9

# variables holding the debounced measurements
my $v24 = 0;
my $v05 = 0;
my $cur = 0;
my $temp = 0;
my $distance = 0;
my $level = 0;

# get ambient temperature
open TEMP, "/sys/bus/w1/devices/28-00000529b5b1/w1_slave";
my @lines = <TEMP>;
close(TEMP);
my $temp_position = index($lines[-1], "t=");
my $ambient = substr($lines[-1], $temp_position + 2) / 1000;
undef @lines;	#clear array;

# temperature compensation for internal 3.3V reference
$temp = `cat /sys/class/thermal/thermal_zone0/temp` / 1000;
$v33 = $v33 + ($temp - 48) * 0.0016;

# read distance to water surface from external ultrasonic C-binary
$distance=`renice -5 $$ >/dev/null 2>&1; /home/vic/ultrasonic $ambient`;

if ($distance < 20.0 || $distance > $ultrasonic) {	# range check to avoid false readings
  # system(sprintf("logger \"ULTRASONIC DISTANCE %.2f cm OUT OF RANGE\"", $distance));
  $distance = 10000;			# generate invalid rrd entry
} elsif ($distance =~ /^(\d+((\.\d+)?))/) {		# verify number format for eval
  # now do the low pass filtering of water level
  $distance = $&;					#extract matched string from regex
  my $aref = lock_retrieve($ustore_name) if -f $ustore_name;
  while (scalar @$aref >= $ustore_size) { shift @$aref; }	# remove older elements
  push @$aref, $distance;
  lock_store($aref, $ustore_name);
  $distance = eval(join("+", @$aref)) / @$aref;		# average of all values in array
  print "***" . $distance . "***\n" . join("+", @$aref) . "\n";
}

$level = $ultrasonic - $distance;	# compute water level
my $levelstr = sprintf("%.1f",$level);	# generate string with precision 1
system("echo $levelstr > /home/vic/water_level");	# make water level available to web if

# build a roughly one second average of 100 samples (debounce)
for (my $i=0; $i < 100; $i++) {
  my $val24 = `/usr/local/bin/gpio -x mcp3004:100:0 aread 100`;
  my $val05 = `/usr/local/bin/gpio -x mcp3004:100:0 aread 102`;
  my $valcc = `/usr/local/bin/gpio -x mcp3004:100:0 aread 103`;
  my $valtemp = `cat /sys/class/thermal/thermal_zone0/temp`;
  $v24 = ($v24 * $i + $val24 * $v33 * 11 / 1024 + $offset) / ($i + 1);
  $v05 = ($v05 * $i + $val05 * $v33 * 25 / 15 / 1024) / ($i + 1);
  $cur = ($cur * $i + ($valcc - $val05 / 2) * $v33 * 25 / 15 / 1024 * 10) / ($i + 1);
  $temp = ($temp * $i + $valtemp / 1000.0) / ($i+1);
  usleep(1000);
}

# check if pump is on
my $ret = system("/home/vic/read_pump >/dev/null");
$ret <<= 8;				# shell exit status resides in the higher 8 bits
my $vbat = $v24 + ($cur * $resistance);	# calculate battery voltage

# print some useful information to a human invoker
printf("VIN:%.2f | VBAT:%.2f | VCC:%.2f | CUR:%.2f | TEMP:%.2f | PUMP=%s \n", $v24, $vbat, $v05, $cur, $temp, $ret ? "ON" : "OFF");
printf("AIRTEMP:%.2f | LEVEL:%.2f\n", $ambient, $level);

# update the RRD database
system("/usr/bin/rrdtool update /home/vic/galini.rrd --template vbat:vcc:current:temp:ambient:level N:$v24:$v05:$cur:$temp:$ambient:$level");

# LOW BATTERY AUTO-OFF: if voltage should fall below this value, swich off pump and LED
if ( ($vbat < 24.2) && $ret ) {
  printf("LOW VOLTAGE SWITCH OFF AT VBAT= %.2f VOLTS\n", $vbat);
  system(sprintf("logger LOW VOLTAGE SWITCH OFF AT VBAT=%.2f VOLTS", $vbat));
  system("/home/vic/pump_off system");
  system("/home/vic/light_off");
}

# HIGH CURRENT AUTO-OFF: if current should rise over this value, switch off pump and light
if ( ($cur > 3.0) && $ret ) {
  printf("OVERCURRENT SWITCH OFF AT %.2f AMPS\n", $cur);
  system(sprintf("logger HIGH CURRENT SWITCH OFF AT %.2f AMPS", $cur));
  system("/home/vic/pump_off system");
  system("/home/vic/light_off");
}

# LOW CURRENT AUTO-OFF: if max current is below this value, we are sucking air so switch off pump and light
# de-bounce values first!
if ( $ret ) {
  my $aref = lock_retrieve($cstore_name) if -f $cstore_name;
  while (scalar @$aref >= $cstore_size) { shift @$aref; }	# remove older elements
  push @$aref, $cur;
  lock_store($aref, $cstore_name);
  $cur = max(@$aref);		# maximum of all values in array
  print "***" . $cur . "***\n" . join("+", @$aref) . "\n";
  # now check if maximum meets criteria
  if ( ($cur < 2.0) && (@$aref >= $cstore_size) ) {
    printf("LOW CURRENT SWITCH OFF AT %.2f AMPS\n", $cur);
    system(sprintf("logger LOW PUMP CURRENT. AIR INTAKE SWITCH OFF AT %.2f AMPS", $cur));
    system("/home/vic/pump_off system");
    system("/home/vic/light_off");
  }
}
else {
  # clear store while pump is not running
  unlink $cstore_name if -f $cstore_name;
}

# HIGH WATER LEVEL AUTO-OFF: if water level should rise over this value, switch off pump
if ( ($level > 99.0) && $ret ) {
  printf("WATER OVERFLOW SWITCH OFF AT %.2f CM\n", $level);
  system(sprintf("logger WATER OVERFLOW SWITCH OFF AT %.2f CM", $level));
  system("/home/vic/pump_off system");
}

# FULL BATTERY AUTO-ON: if voltage should rise over this value, switch on pump
#if ( ($vbat > 27.5) && !$ret ) {
#  printf("AUTO-START PUMP AT VBAT=%.2f VOLTS\n", $vbat);
#  system(sprintf("logger AUTO START PUMP AT VBAT=%.2f VOLTS", $vbat));
#  system("/home/vic/pump_on system");
#}

# stabilize storage in case of power failure
sleep 1;
system("sync");
