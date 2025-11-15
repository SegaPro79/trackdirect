<?php

class gd_gradient_alpha {
    public $image;
    public $width;
    public $height;
    public $direction;
    public $alphastart;
    public $alphaend;
    public $step;
    public $color;

    function __construct($w, $h, $d, $rgb, $as, $ae, $step = 0) {
        $this->width = intval($w);
        $this->height = intval($h);
        $this->direction = $d;
        $this->color = $rgb;
        $this->alphastart = intval($as);
        $this->alphaend = intval($ae);
        $this->step = intval(abs($step));

        if (function_exists('imagecreatetruecolor')) {
            $this->image = imagecreatetruecolor($this->width, $this->height);
        } elseif (function_exists('imagecreate')) {
            $this->image = imagecreate($this->width, $this->height);
        } else {
            die('Unable to create an image');
        }

        imagealphablending($this->image, false);
        imagesavealpha($this->image, true);

        $this->fillalpha($this->image, $this->direction, $this->color, $this->alphastart, $this->alphaend);
    }

    function get_image() {
        return $this->image;
    }

    function display($im) {
        if (function_exists("imagepng")) {
            header("Content-type: image/png");
            imagepng($im);
        } elseif (function_exists("imagegif")) {
            header("Content-type: image/gif");
            imagegif($im);
        } elseif (function_exists("imagejpeg")) {
            header("Content-type: image/jpeg");
            imagejpeg($im, "", 0.5);
        } elseif (function_exists("imagewbmp")) {
            header("Content-type: image/vnd.wap.wbmp");
            imagewbmp($im);
        } else {
            die("Doh! No graphical functions on this server?");
        }
        return true;
    }

    function fillalpha($im, $direction, $rgb, $as, $ae) {
        list($r, $g, $b) = $this->hex2rgb($rgb);
        $a1 = $this->a2sevenbit($as);
        $a2 = $this->a2sevenbit($ae);
        $line_numbers = 0; // Standard-Initialisierung

        switch ($direction) {
            case 'horizontal':
                $line_numbers = intval(imagesx($im));
                $line_width = intval(imagesy($im));
                break;
            case 'vertical':
                $line_numbers = intval(imagesy($im));
                $line_width = intval(imagesx($im));
                break;
            case 'ellipse':
            case 'ellipse2':
            case 'circle':
            case 'circle2':
                $width = intval(imagesx($im));
                $height = intval(imagesy($im));
                $line_numbers = min($width, $height);
                $center_x = intval(round($width / 2));
                $center_y = intval(round($height / 2));
                break;
            case 'square':
            case 'rectangle':
                $width = intval(imagesx($im));
                $height = intval(imagesy($im));
                $line_numbers = intval(max($width, $height) / 2);
                break;
            case 'diamond':
                $width = intval(imagesx($im));
                $height = intval(imagesy($im));
                $line_numbers = min($width, $height);
                break;
            default:
                $line_numbers = 0;
        }

        for ($i = 0; $i < $line_numbers; $i += (1 + $this->step)) {
            $a = intval(round($a1 + ($a2 - $a1) * ($i / $line_numbers)));
            $fill = imagecolorallocatealpha(
                $im,
                intval(round($r)),
                intval(round($g)),
                intval(round($b)),
                $a
            );

            switch ($direction) {
                case 'vertical':
                    imagefilledrectangle($im, 0, $i, $line_width, $i + $this->step, $fill);
                    break;
                case 'horizontal':
                    imagefilledrectangle($im, $i, 0, $i + $this->step, $line_width, $fill);
                    break;
                case 'ellipse':
                case 'circle':
                    imagefilledellipse(
                        $im,
                        $center_x,
                        $center_y,
                        intval(round(($line_numbers - $i))),
                        intval(round(($line_numbers - $i))),
                        $fill
                    );
                    break;
                case 'square':
                    imagefilledrectangle(
                        $im,
                        intval(round($i * $width / $height)),
                        intval(round($i * $height / $width)),
                        intval(round($width - ($i * $width / $height))),
                        intval(round($height - ($i * $height / $width))),
                        $fill
                    );
                    break;
            }
        }
    }

    function hex2rgb($color) {
        $color = str_replace('#', '', $color);
        $s = strlen($color) / 3;
        return [
            hexdec(str_repeat(substr($color, 0, $s), 2 / $s)),
            hexdec(str_repeat(substr($color, $s, $s), 2 / $s)),
            hexdec(str_repeat(substr($color, 2 * $s, $s), 2 / $s))
        ];
    }

    function a2sevenbit($alpha) {
        return intval(abs($alpha - 255) >> 1);
    }
}
