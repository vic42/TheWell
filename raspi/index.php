<html>
<head>
<title>The Well Project</title>
</head>
<body>
<table cellpadding=5>
<tr><th colspan=2 align=center><h2>Welcome to the <a href="/">Galini</a> Well Project!</h2></th></tr>
<?php

// error_reporting(E_ALL);
// ini_set('display_errors', TRUE);
// ini_set('display_startup_errors', TRUE);

// find out who is calling the script and their permissions
$admins = array("vic", "galini");
$user= $_SERVER['PHP_AUTH_USER'];

echo "<tr><th colspan=2><font size=+1><u>Hello " . $user . "! Current Well Status:</u></font></th></tr><tr>";

// only andmins are allowed to switch the pump
if (in_array($user, $admins)) {
  if ( $_GET['pump'] == "on" ) {
    system('/home/vic/pump_on ' . $user);
    usleep(500000);
  }
  elseif ( $_GET['pump'] == "off" ) {
    system('/home/vic/pump_off ' . $user);
    usleep(500000);
  }
}

$exptime = intval($_GET['exposure']);
$quality = intval($_GET['quality']);
if ( !$exptime || $exptime < 200 || $exptime > 5000 ) { $exptime=500; }
if ( !$quality || $quality < 1 || $quality > 100 ) { $quality=5; }

switch ($_GET['camera']) {
  case "snap":
    system("/usr/bin/raspistill -n -t " . $exptime . " -q " . $quality . " -o /var/www/pictures/last.jpg");
    break;
  case "flash":
    system('/home/vic/light_on');
    system("/usr/bin/raspistill -n -t " . $exptime . " -q " . $quality . " -o /var/www/pictures/last.jpg");
    system('/home/vic/light_off');
    break;
  case "video": break;
}

$last_line = system('/home/vic/read_pump >/dev/null', $retval);
if ($retval == 1) {
    echo '<td bgcolor=green align=center><font size=+2 color=white><blink><b>PUMP IS ON</blink></font></td>';
    echo '<td bgcolor=grey align=center><font size=+2><a href=index.php?pump=off style="color:red"><b>SWITCH OFF</a></font></td>';
  }
  else
  {
    echo '<td bgcolor=grey align=center><font size=+2><a href=index.php?pump=on style="color:green"><b>SWITCH ON</a></font></td>';
    echo '<td bgcolor=red align=center><font size=+2 color=white><b>PUMP IS OFF</font></td>';
  }

$v33 = 3.40;
#$v33 = 3.49;
$temp = `cat /sys/class/thermal/thermal_zone0/temp` / 1000.0;

// temperature compensation for Raspi internal 3.3V regulator
$v33 = $v33 + ($temp-48) * 0.0016;

$offset = 0.30;		// Schottky diode voltage
include 'resistance.php';
$val24 = `/usr/local/bin/gpio -x mcp3004:100:0 aread 100`;
$val05 = `/usr/local/bin/gpio -x mcp3004:100:0 aread 102`;
$valcc = `/usr/local/bin/gpio -x mcp3004:100:0 aread 103`;
$v24 = $val24 * $v33 * "11" / "1024" + $offset;
$v05 = $val05 * $v33 * "25" / "15" / "1024";
$cur = ($valcc - $val05 / "2") * $v33 * "25" / "15" / "1024" * "10";
$vwell = $v24 + $cur * $resistance;
$ambient = `/home/vic/temp.sh`;
$dist = `cat /home/vic/water_level`;
?>
</tr>
<tr>
  <td><a href="index.php?camera=snap&quality=5&exposure=500"><img src=camera-icon.jpg></a></td>
  <td><a href="index.php?camera=flash&quality=20&exposure=500"><img src=slr.png></a></td>
</tr>
<tr>
  <td bgcolor="#ffffa0"><b>Vin = <?php echo number_format($v24, 2) . "V"; ?>
    | Vbat = <?php echo number_format($vwell, 2) . "V"; ?> </b></td>
  <td bgcolor="#ffa0a0"><b>Water Level: <?php echo round( $dist, 1, PHP_ROUND_HALF_UP); ?> cm</b></td>
</tr>
<tr>
  <td bgcolor="#ffa0ff"><b>System Current: <?php echo number_format($cur,2) . "A"; ?></b></td>
  <td bgcolor="#dddd70"><b>Air Temperature: <?php echo round( $ambient, 1, PHP_ROUND_HALF_UP); ?>&deg;C</b></td>
</tr>
<tr>
  <td bgcolor="#a0ffa0"><b>VCC = <?php echo number_format($v05, 2) . "V"; ?></b></td>
  <td bgcolor="#a0ffff"><b>CPU Temperature: <?php echo number_format($temp,0); ?>&deg;C</b></td>
</tr>
<tr>
  <td><font size=+1><b><a href=stats.php>Statistics</a> or <a href="logfile.php?lines=30">Logfile</a>
    or <a href="/munin/system-day.html">Munin</a></b></font></td>
  <td><font size=+1><b><a href=well.pdf>Circuit Diagrams</a></b></font></td>
</tr>
<tr>
  <td><?php print date("r"); ?></td>
</tr>
</table>
<?php
  if ($_GET['camera'] == snap || $_GET['camera'] == flash) {
    echo '<img width=100% src=pictures/last.jpg>';
  }
?>
</body>
</html>
