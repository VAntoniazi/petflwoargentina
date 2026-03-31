<?php
$pixel = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
header('Content-Type: image/png');
header('Content-Length: ' . strlen($pixel));
echo $pixel;