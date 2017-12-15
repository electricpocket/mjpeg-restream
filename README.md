# mjpeg-restream
Re-stream mjpeg camera footage to the masses. Use a web server to re-stream footage from your camera to bypass connection limits and stress on your mjpeg ready web camera.

This script connects to a remote web camera, and reads the mjpeg stream from it. The read stream is delivered to the user, and also buffered into the RAM of the webserver. Any additional users visiting the script will be served the stream from the RAM rather than creating an additional connection to the web camera. The stream will only run for 45 seconds by default, and was designed for use on a web page that refreshed every 45 seconds to pull in new ads. Modify as you need.

The stream buffer is done to RAM as buffering to disk was rather intensive on older magnetic drives. It might be ok for more modern SSD's, but RAM is more efficient all round we've found.
 
Enjoy!
Questions? Contact us via http://webmad.co.nz

Installation: 

Requires php-gd (graphic goodies)

-sudo yum install php-gd

-sudo apachectl restart

 - edit stream.php, and modify the camera IP address, port, and url to the mjpeg stream.
 - Modify any of the image settings - ie overlay image and fallback image locations. Note - if you don't want an overlay image, use a fully transparent png, or edit the code to remove this functionality yourself.
 - modify the mjpeg boundary text - this varies between different model cameras. Studying the raw headers of your mjpeg stream from the camera, or the content of the stream to find frame boundary text. For the logitech c920 streamed via mjpg-streamer this is 'boundarydonotcross'

Usage:
```
<img src="stream.php"/>
```
