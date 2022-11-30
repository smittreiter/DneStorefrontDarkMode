<?php declare(strict_types=1);

namespace Dne\StorefrontDarkMode\Subscriber;

use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;
use Shopware\Storefront\Theme\Event\ThemeCopyToLiveEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use const DIRECTORY_SEPARATOR;
use const PHP_EOL;

class ScssVariablesSubscriber implements EventSubscriberInterface
{
    private Filesystem $themeFilesystem;

    public function __construct(Filesystem $themeFilesystem)
    {
        $this->themeFilesystem = $themeFilesystem;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ThemeCopyToLiveEvent::class => 'oneThemeCopyToLive',
        ];
    }

    public function oneThemeCopyToLive(ThemeCopyToLiveEvent $event): void
    {
        $cssPath = $event->getTmpPath() . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'all.css';

        if (!$this->themeFilesystem->has($cssPath)) {
            return;
        }

        try {
            $css = $this->themeFilesystem->read($cssPath);
        } catch (FileNotFoundException) {
            return;
        }

        // make these configurable
        $minLightness = 10;
        $saturationThreshold = 65;

        // keep black shadows, make this optional
        $css = preg_replace_callback('/box-shadow:(.*)#(0{3})/', function (array $matches): string {
            return str_replace('#000', sprintf('hsl(%sdeg, %s%%, %s%%)', ...$this->hex2hsl('#000')), $matches[0]);
        }, $css);

        // replace half-transparent white overlays with black, make this optional
        $css = preg_replace_callback('/(background|background-color):(.*)rgba\(255, 255, 255, (.*)\)/', function (array $matches): string {
            $rgba = sprintf('rgba(255, 255, 255, %s)', $matches[3]);
            $hsla = sprintf('hsla(var(--color-fff), %s)', $matches[3]);

            return str_replace($rgba, $hsla, $matches[0]);
        }, $css);

        preg_match_all('/#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})/', $css, $matches);
        $hexColors = array_unique($matches[0] ?? []);
        $lightColors = $darkColors = [];

        foreach ($hexColors as $hexColor) {
            [$hue, $saturation, $lightness] = $this->hex2hsl($hexColor);

            if ($saturation > $saturationThreshold) {
                continue;
            }

            $variable = sprintf('--color-%s', ltrim($hexColor, '#'));
            $css = str_replace($hexColor, sprintf('hsl(var(%s))', $variable), $css);

            $lightColors[] = sprintf('%s: %sdeg, %s%%, %s%%', $variable, $hue, $saturation, $lightness);

            $lightness = 100 - $lightness;
            $lightness = min($lightness + $minLightness, 100);

            $darkColors[] = sprintf('%s: %sdeg, %s%%, %s%%', $variable, $hue, $saturation, $lightness);
        }

        if (empty($darkColors)) {
            return;
        }

        $css .= PHP_EOL . sprintf(':root { %s }', implode('; ', $lightColors));
        $css .= PHP_EOL . sprintf('@media (prefers-color-scheme: dark) { :root { %s } }', implode('; ', $darkColors));

        $this->themeFilesystem->put($cssPath, $css);
    }

    private function hex2hsl(string $hex): array
    {
        $hexstr = ltrim($hex, '#');
        if (strlen($hexstr) === 3) {
            $hexstr = $hexstr[0] . $hexstr[0] . $hexstr[1] . $hexstr[1] . $hexstr[2] . $hexstr[2];
        }
        $r = hexdec($hexstr[0] . $hexstr[1]) / 255;
        $g = hexdec($hexstr[2] . $hexstr[3]) / 255;
        $b = hexdec($hexstr[4] . $hexstr[5]) / 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        $d = $max - $min;
        $h = $s = 0;
        if((float) $d !== 0.0) {
            $s = $d / (1 - abs((2 * $l) - 1));
            switch($max) {
                case $r:
                    $h = 60 * fmod((($g - $b) / $d), 6);
                    if ($b > $g) { //will have given a negative value for $h
                        $h += 360;
                    }
                    break;
                case $g:
                    $h = 60 * (($b - $r) / $d + 2);
                    break;
                case $b:
                    $h = 60 * (($r - $g) / $d + 4);
                    break;
            }
        }

        return [round($h), round($s * 100), round($l * 100)];
    }
}
