<?php

class gd_heatmap {
    private $config;
    private $debug_log;
    private $im;

    public function __construct($data, $config = array()) {
        $default_config = array(
            'debug' => FALSE,
            'r' => 50,
            'width' => 640,
            'height' => 480,
            'noc' => 16,
            'dither' => FALSE,
            'format' => 'png',
            'fill_with_smallest' => false,
        );

        $this->config = array_merge($default_config, $config);
        $this->debug_log = '';
        $this->generate_image($data);
    }

    private function generate_image($data) {
        $this->im = imagecreatetruecolor($this->config['width'], $this->config['height']);

        // Transparenter Hintergrund
        $transparent = imagecolorallocatealpha($this->im, 255, 255, 255, 127);
        imagefill($this->im, 0, 0, $transparent);
        imagealphablending($this->im, false);
        imagesavealpha($this->im, true);

        // Maximalwert für die Normalisierung
        $max_value = 1;
        foreach ($data as $point) {
            $max_value = max($max_value, $point[2]);
        }

        // Punkte zeichnen
        foreach ($data as $point) {
            $value = intval(round($point[2] / $max_value * 255));
            $x = intval(round($point[0]));
            $y = intval(round($point[1]));
            $this->draw_point($x, $y, $value);
        }
    }

    /**
     * Berechnet die Farbe basierend auf der Intensität.
     * Gelb (niedrige Intensität) -> Rot (hohe Intensität).
     */
    private function get_color($value) {
        // Normalisierung der Intensität auf den Bereich 0-1
        $intensity = max(0, min(1, $value / 255));

        // Farbinterpolation (Gelb -> Rot)
        $r = 255; // Rot bleibt konstant
        $g = intval(255 * (1 - $intensity)); // Grün nimmt ab
        $b = 0; // Blau bleibt konstant

        return [$r, $g, $b];
    }

    private function draw_point($x, $y, $value) {
        $radius = $this->config['r'];
        // Alpha-Wert zwischen 0 und 127 begrenzen
        $alpha = max(0, min(127, 127 - ($value / 2))); // Transparenz dynamisch anpassen

        // Farbe basierend auf der Intensität berechnen
        list($r, $g, $b) = $this->get_color($value);

        // Farbe mit Transparenz zuweisen
        $color = imagecolorallocatealpha($this->im, $r, $g, $b, $alpha);

        // Punkt zeichnen
        imagefilledellipse($this->im, $x, $y, $radius, $radius, $color);
    }

    public function output($filename = null) {
        if (!$filename) {
            header('Content-type: image/' . $this->config['format']);
        }

        switch ($this->config['format']) {
            case 'png':
                imagepng($this->im, $filename);
                break;
            case 'jpeg':
                imagejpeg($this->im, $filename);
                break;
        }

        imagedestroy($this->im);
    }

    public function get_config($key) {
        return $this->config[$key] ?? null;
    }

    private function log_debug($message) {
        if ($this->config['debug']) {
            $this->debug_log .= $message . "\n";
        }
    }

    public function debug_log() {
        return $this->debug_log;
    }
}
