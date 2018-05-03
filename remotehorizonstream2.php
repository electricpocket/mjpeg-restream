<?php

/*

Description: mjpeg restreaming script with image overlay capability. 
Author: Stephen Price / Webmad
Version: 1.0.0
Author URI: http://www.webmad.co.nz
Usage: <img src="stream.php" />
Notes: If you are keen to have image overlays clickable, use html elements overlaying the <img> element (ie: wrap <img> in a <div> with position:relative; and add an <a> element with display:block;position:absolute;bottom:0px;left:0px;width:100%;height:15px;background:transparent;z-index:2;)
Requirements: php5+ compiled with --enable-shmop
          php-gd (sudo yum install php-gd)

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
$url = "/?action=stream";
$sonyrtsp=false;
if ($_GET['port']==7193) 
{
	$sonyrtsp=true;//special case for sony cameras we use cvlc to read rtsp stream from 10.106.16.84/media/video2
}

$horizon = false; //draw a horizon line?

// Image settings:
$overlay = "frlogo.png"; //image that will be superimposed onto the stream
$fallback = $_GET['port'] . "_still.jpg"; //image that will get updated every 20 frames or so for browsers that don't support mjpeg streams
$boundary = "boundarydonotcross";
if ($sonyrtsp) //special case for sony cameras we use cvlc to read rtsp stream from 10.106.16.84/media/video2
{
	$url = "/webcam";
	$boundary = "7b3cc56e5f51db803f790dad720ed50a";
}
$timelimit = 5; //number of seconds to run for
$cameraOffset = 0; //horizontal angle camera is pointing
$cameraUpsideDown = false; //is the camera mounted upside down
if ($_GET['port'] == 7124 || $_GET['port'] == 7186 || $_GET['port'] == 7154 || $_GET['port'] == 7155)
	$cameraUpsideDown = true;
//finlandia
if (isset($_GET['flip']))
	$cameraUpsideDown = $_GET['flip'] > 0;

///////////////////////////////////////////////////////////////////////////////////////////////////
// Stuff below here will break things if edited. Avert your eyes unless you know what you are doing
// (or can make it look like you know what you are doing, and won't get naggy if you can't fix it.)
///////////////////////////////////////////////////////////////////////////////////////////////////

$start = time();
$in2 = imageCreateFromPNG($overlay);

fresh();

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
	global $data, $tmid, $tdmid, $start, $in2, $host, $port, $url,$cameraUpsideDown, $boundary, $fallback, $timelimit;

	$fp = @fsockopen($host, $port, $errno, $errstr, 10);
	//error_log(date('Y-m-d H:i:s')." stream: ".$host.",".$port.",".$errno." error ".$errstr."\n", 3, 'streamerror.log');
	if ($fp) 
	{
		$username = "fleetrange";
		$password = trim($port - 10000);
		//error_log(date('Y-m-d H:i:s')." stream: ".$username.",".$port.",".$password." error ".$errstr."\n", 3, 'streamerror.log');

		$auth = base64_encode($username . ":" . $password);
		$out = "GET $url HTTP/1.1\r\n";
		$out .= "Host: $host\r\n";
		$out .= "Authorization: Basic $auth\r\n";
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
				if ($cameraUpsideDown)
				{
					if (function_exists("imageflip")) imageflip($img, IMG_FLIP_BOTH);
					else $img=imagerotate($img, 180, 0);
				}
				output($img, true); //,null,60
				$imgstr = ob_get_contents();
				ob_end_clean();

				header("Content-Type: image/jpeg");
				header("Content-Length: ". strlen($imgstr));
				echo $imgstr; 
				
				flush();
				fclose($fp);
				break;
			}
		}
	} 
	else {
		$img = imageCreateFromJPEG($fallback);

		imagestring($in, 3, 25, 180, "Could not connect to the camera source",
				imagecolorallocate($in, 255, 255, 255));
		imagestring($in, 3, 65, 195, "Please try again later...",
				imagecolorallocate($in, 255, 255, 255));

		ob_start();
		output($img);
		$imgstr = ob_get_contents();
		ob_end_clean();
		header("Content-Type: image/jpeg");
		header("Content-Length: ". strlen($imgstr));
		echo $imgstr;
		flush();
	}
}