<?php
//uses vlc on the BBG to read the rtsp stream and publish it as http to port 8080 locally
//ssh tunnel then gets us to aiswatch.net....
//need to connect re-stream to this
//and set up a conf job to run the cvlc command.
//cvlc -vvvv rtsp://10.106.16.84/media/video2 --sout '#standard{access=http{mime=multipart/x-mixed-replace;boundary=--7b3cc56e5f51db803f790dad720ed50a},mux=mpjpeg,dst=localhost:8080}'
/*

Description: mjpeg restreaming script with image overlay capability. 
Author: Stephen Price / Webmad
Version: 1.0.0
Author URI: http://www.webmad.co.nz
Usage: <img src="stream.php" />
Notes: If you are keen to have image overlays clickable, use html elements overlaying the <img> element (ie: wrap <img> in a <div> with position:relative; and add an <a> element with display:block;position:absolute;bottom:0px;left:0px;width:100%;height:15px;background:transparent;z-index:2;)
Requirements: php5+ compiled with --enable-shmop
          php-gd (sudo yum install php-gd)
//https://aiswatch.net/fleetrange/mjpeg-restream/sonyremotestream.php?port=7193
 */


if (!ini_get('date.timezone'))
{
	date_default_timezone_set('UTC');
}

// These settings would read an mjpeg stream from mjpg-streamer on localhost 
if (!isset($_GET['port'])) {
	header("HTTP/1.1 401 Unauthorized");
	header("Status: 401  Unauthorized");
	error_log(date('Y-m-d H:i:S') . "Port number missing " . "\n", 3, 'mjpglog.txt');
	exit();

}
$host = "localhost";
$port = $_GET['port'] + 10000;//"17125";
$url = "";
$horizon = false; //draw a horizon line?

// Image settings:
$overlay = "frlogo.png"; //image that will be superimposed onto the stream
$fallback = $_GET['port'] . "_still.jpg"; //image that will get updated every 20 frames or so for browsers that don't support mjpeg streams
$boundary = "7b3cc56e5f51db803f790dad720ed50a";
$timelimit = 300; //number of seconds to run for
$cameraOffset = 0; //horizontal angle camera is pointing
$cameraUpsideDown = false; //is the camera mounted upside down
if ($_GET['port'] == 7124)
	$cameraUpsideDown = true;
//finlandia
if (isset($_GET['flip']))
	$cameraUpsideDown = $_GET['flip'] > 0;
///////////////////////////////////////////////////////////////////////////////////////////////////
// Stuff below here will break things if edited. Avert your eyes unless you know what you are doing
// (or can make it look like you know what you are doing, and won't get naggy if you can't fix it.)
///////////////////////////////////////////////////////////////////////////////////////////////////

set_time_limit($timelimit);

$start = time();
$in2 = imageCreateFromPNG($overlay);

//create different shared memory for each camera
$tmid = shmop_open($_GET['port'], 'c', 0777, 1024);
$tdmid = shmop_open($port, 'c', 0777, 102400);
//$tmid = shmop_open(0xff4, 'c', 0777, 1024);
//$tdmid = shmop_open(0xff6, 'c', 0777, 102400);


$data = unserialize(trim(shmop_read($tmid, 0, 1024)));
if (!isset($data['updated']) || $data['updated'] < (time() - 5)) {
	fresh();
}

header('Accept-Range: bytes');
header('Connection: close');
header('Content-Type: multipart/x-mixed-replace;boundary=' . $boundary);
header('Cache-Control: no-cache');

$curframe = $data['frame'];

//var_dump($frame);
while ($data['updated'] > (time() - 5)) {
	if ($curframe != $data['frame']) {
		$curframe = $data['frame'];

		$frames = unserialize(trim(shmop_read($tdmid, 0, 102400)));
		$key = array_pop(array_keys($frames));
		echo "--$boundary\r\nContent-Type: image/jpeg\r\nContent-Length: "
				. strlen($frames[$key]) . "\r\n\r\n" . $frames[$key];
		flush();
	}
	usleep(50000);
	$data = unserialize(trim(shmop_read($tmid, 0, 1024)));
}
if ((time() - $start) < 30) {
	fresh();
}

shmop_close($tdmid);
shmop_close($tmid);

exit;

function output($in) {
	global $in2, $cameraOffset, $cameraUpsideDown, $horizon;
	$string = date('r');
	//read in the pitch and roll measurements

	if ($horizon) {
		$attitude_json = file_get_contents('../sensors/attitude.json');

		//roll and pitch are in degrees
		//{"pitch":"20.0","roll":"-7.0"}
		$attitude = json_decode($attitude_json, true);
		if ($cameraUpsideDown) {
			$attitude['pitch'] = -$attitude['pitch'];
			$attitude['roll'] = -$attitude['roll'];
		}
		$string = $string . " ,pitch:" . $attitude['pitch'] . ",roll:"
				. $attitude['roll'];
	}

	
	if ($cameraUpsideDown)
	{	
		if (function_exists("imageflip")) imageflip($in, IMG_FLIP_BOTH);
		else $in=imagerotate($in, 180, 0);
	}
	
	imagecopy($in, $in2, 0, 0, 0, 0, 640, 480);
	//imageantialias($in,true); //requires php 7.2
	$font = 4;
	$width = imagefontwidth($font) * strlen($string);
	$height = imagefontheight($font) + 10;
	$x = imagesx($in) - $width - 10;
	$y = imagesy($in) - $height;
	$backgroundColor = imagecolorallocate($in, 255, 255, 255);
	// Black background and white text
	$bg = imagecolorallocate($in, 0, 0, 0);
	$textColor = imagecolorallocate($in, 255, 255, 255);
	//Create background
	imagefilledrectangle($in,  $x, $y, imagesx($in)-10, imagesy($in)-10, $bg);
	
	imagestring($in, $font, $x, $y, $string, $textColor);
	if ($horizon) {
		$x1 = 0;
		$x2 = imagesx($in);
		$dY = ($x2 / 2.0) * tan(deg2rad($attitude['roll']));
		//http://therandomlab.blogspot.co.uk/2013/03/logitech-c920-and-c910-fields-of-view.html
		//VFOV for the logitech is 43 degrees for 16:9 aspect ratio
		$y0 = imagesy($in) / 2.0;

		$dy0 = (($attitude['pitch'] + $cameraOffset) / 43.3) * $y0 * 2.0;
		$y1 = $y0 + $dy0 + $dY;
		$y2 = $y0 + $dy0 - $dY;
		$lineColor = imagecolorallocate($in, 255, 255, 255);
		imageline($in, $x1, $y1, $x2, $y2, $lineColor);
	}

	imagejpeg($in, NULL, 60);
}

function fresh() {
	global $data, $tmid, $tdmid, $start, $in2, $host, $port, $url, $boundary, $fallback, $timelimit;

	if (!headers_sent()) {
		header('Accept-Range: bytes');
		header('Connection: close');
		header('Content-Type: multipart/x-mixed-replace;boundary=' . $boundary);
		header('Cache-Control: no-cache');
	}

	$data['updated'] = time();
	$data['frame'] = 0;
	$frames = array();
	shmop_write($tmid, str_pad(serialize($data), 1024, ' '), 0);

	$fp = @fsockopen($host, $port, $errno, $errstr, 10);
	//error_log(date('Y-m-d H:i:s')." stream: ".$host.",".$port.",".$errno." error ".$errstr."\n", 3, 'streamerror.log');
	if ($fp) {
		$username = "fleetrange";
		$password = trim($port - 10000);
		//error_log(date('Y-m-d H:i:s')." stream: ".$username.",".$port.",".$password." error ".$errstr."\n", 3, 'streamerror.log');

		$auth = base64_encode($username . ":" . $password);
		$out = "GET $url HTTP/1.1\r\n";
		$out .= "Host: $host\r\n";
		//$out .= "Authorization: Basic $auth\r\n";
		$out .= "\r\n";
		fwrite($fp, $out);
		$ec = "";
		$in = false;
		$buffer = '';
		while (!feof($fp)) {
			$part = fgets($fp);
			if (strstr($part, '--' . $boundary)) {
				$in = true;
			}
			$buffer .= $part;
			$part = $buffer;
			if (substr(trim($part), 0, 2) == "--")
				$part = substr($part, 3);
			$part = substr($part,
					strpos($part, '--' . $boundary) + strlen('--' . $boundary));
			$part = trim(substr($part, strpos($part, "\r\n\r\n")));
			$part = substr($part, 0, strpos($part, '--' . $boundary));

			$img = @imagecreatefromstring($part);
			if ($img) {
				$buffer = substr($buffer, strpos($buffer, $part)
						+ strlen($part));
				ob_start();
				output($img, true); //,null,60
				$imgstr = ob_get_contents();
				ob_end_clean();
				$data['frame']++;
				$data['updated'] = time();
				while (count($frames) > 2)
					array_shift($frames);
				$frames[] = $imgstr;
				shmop_write($tdmid, str_pad(serialize($frames), 102400, ' '), 0);
				shmop_write($tmid, str_pad(serialize($data), 1024, ' '), 0);

				echo "--$boundary\r\nContent-Type: image/jpeg\r\nContent-Length: "
						. strlen($imgstr) . "\r\n\r\n" . $imgstr; //$frames[$data['frame']]
				if (($data['frame'] / 20) - (ceil($data['frame'] / 20)) == 0)
					file_put_contents($fallback, $imgstr);
				if ((time() - $start) > $timelimit) {
					exit;
				}
				flush();
			}
		}
	} else {
		$img = imageCreateFromJPEG($fallback);

		imagestring($in, 3, 25, 180, "Could not connect to the camera source",
				imagecolorallocate($in, 255, 255, 255));
		imagestring($in, 3, 65, 195, "Please try again later...",
				imagecolorallocate($in, 255, 255, 255));

		ob_start();
		output($img);
		$imgstr = ob_get_contents();
		ob_end_clean();
		echo "--$boundary\r\nContent-Type: image/jpeg\r\nContent-Length: "
				. strlen($imgstr) . "\r\n\r\n" . $imgstr;
		flush();
	}
}