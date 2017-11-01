<html>
<head>
  <title>The Well Project Stats</title>
  <meta http-equiv="refresh" content="300" >
</head>
<body>
<center><h2><a href="/">Galini</a> Well Statistics</h2>
<h3>
  <a href="<?php echo $_SERVER['PHP_SELF'] . "?hours=24"; ?>">[24 Hours]</a>
  <a href="<?php echo $_SERVER['PHP_SELF'] . "?days=7"; ?>">[1 Week]</a>
  <a href="<?php echo $_SERVER['PHP_SELF'] . "?days=30"; ?>">[30 Days]</a>
  <a href="<?php echo $_SERVER['PHP_SELF'] . "?days=90"; ?>">[90 Days]</a>
  <a href="<?php echo $_SERVER['PHP_SELF'] . "?days=365"; ?>">[365 Days]</a>
</h3>
</center>
<?php

$now = time();
include 'resistance.php';	// conductor resistance between well and raspberry

// handle the time interval parameters from URL
$hours = $_GET['hours'];
$days = $_GET['days'];
if ($hours < 1) $hours = 24;	// default interval
$label = "Hours";
$amount = $hours;
if ($days != 0) {
  $hours = $days * 24;
  $label = "Days";
  $amount = $days;
}

// calculate values
// current
system("/usr/bin/rrdtool graph /var/www/cur_d.png --vertical-label='System Current (A)' --start " . ($now-$hours*60*60+10) . " --end " . $now .
  " -w 720 -h 150 --right-axis 1:0 DEF:CUR=/home/vic/galini.rrd:current:AVERAGE LINE:CUR#800080 >/dev/null");
// calculated "real" battery voltage / current-corrected
system("/usr/bin/rrdtool graph /var/www/realvbat_d.png --vertical-label='Well Battery Voltage (V)' --start " . ($now-$hours*60*60+10) . " --end " . $now .
  " -w 720 -h 150 --right-axis 1:0 DEF:vbat=/home/vic/galini.rrd:vbat:AVERAGE DEF:cur=/home/vic/galini.rrd:current:AVERAGE " . 
  " CDEF:realbat=vbat,cur,$resistance,*,+ LINE:realbat#B00000 >/dev/null");
// ambient temperature
system("/usr/bin/rrdtool graph /var/www/ambient_d.png --vertical-label='Ambient Temperature (°C)' --start " . ($now-$hours*60*60+10) . " --end " . $now .
  " -w 720 -h 150 --right-axis 1:0 DEF:AMBIENT=/home/vic/galini.rrd:ambient:AVERAGE LINE:AMBIENT#007000 >/dev/null");
// water level
system("/usr/bin/rrdtool graph /var/www/level_d.png --vertical-label='Water Level (cm)' --start " . ($now-$hours*60*60+10) . " --end " . $now .
  " -w 720 -h 150 --right-axis 1:0 DEF:LEVEL=/home/vic/galini.rrd:level:AVERAGE LINE:LEVEL#0000A0 >/dev/null");
?>

<h3>Last <?php echo $amount . " " . $label; ?></h3>
<img src=level_d.png>
<img src=realvbat_d.png>
<img src=cur_d.png>
<img src=ambient_d.png>

</body>
</html>
