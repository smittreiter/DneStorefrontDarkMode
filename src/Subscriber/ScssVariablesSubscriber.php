<?php declare(strict_types=1);

namespace Dne\StorefrontDarkMode\Subscriber;

use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;
use Shopware\Core\Framework\Plugin\Event\PluginPreDeactivateEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Event\ThemeCompilerConcatenatedStylesEvent;
use Shopware\Storefront\Theme\Event\ThemeCopyToLiveEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Service\ResetInterface;
use const DIRECTORY_SEPARATOR;
use const PHP_EOL;

class ScssVariablesSubscriber implements EventSubscriberInterface, ResetInterface
{
    private const DEFAULT_MIN_LIGHTNESS = 15;
    private const DEFAULT_SATURATION_THRESHOLD = 55;

    private Filesystem $themeFilesystem;

    private SystemConfigService $configService;

    private bool $disabled = false;

    private ?string $currentSalesChannelId = null;

    public function __construct(Filesystem $themeFilesystem, SystemConfigService $configService)
    {
        $this->themeFilesystem = $themeFilesystem;
        $this->configService = $configService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PluginPreDeactivateEvent::class => ['onPluginPreDeactivate', 999],
            ThemeCompilerConcatenatedStylesEvent::class => 'onThemeCompilerConcatenatedStyles',
            ThemeCopyToLiveEvent::class => 'onThemeCopyToLive',
        ];
    }

    public function reset(): void
    {
        $this->currentSalesChannelId = null;
        $this->disabled = false;
    }

    public function onPluginPreDeactivate(PluginPreDeactivateEvent $event): void
    {
        $this->disabled = $event->getPlugin()->getName() === 'DneStorefrontDarkMode';
    }

    public function onThemeCompilerConcatenatedStyles(ThemeCompilerConcatenatedStylesEvent $event): void
    {
        $this->currentSalesChannelId = $event->getSalesChannelId();
    }

    public function onThemeCopyToLive(ThemeCopyToLiveEvent $event): void
    {
        $cssPath = $event->getTmpPath() . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'all.css';

        if (!$this->themeFilesystem->has($cssPath) || $this->disabled) {
            return;
        }

        try {
            $css = $this->themeFilesystem->read($cssPath);
        } catch (FileNotFoundException) {
            return;
        }

        $domain = 'DneStorefrontDarkMode.config';
        $config = $this->configService->getDomain($domain, $this->currentSalesChannelId, true);

        // configuration
        $minLightness = $config[$domain . '.minLightness'] ?? self::DEFAULT_MIN_LIGHTNESS;
        $saturationThreshold = $config[$domain . '.saturationThreshold'] ?? self::DEFAULT_SATURATION_THRESHOLD;
        $ignoredHexCodes = explode(',', str_replace(' ', '', strtolower($config[$domain . '.ignoredHexCodes'] ?? '')));
        $invertBlackShadows = $config[$domain . '.invertBlackShadows'] ?? false;
        $keepWhiteOverlays = $config[$domain . '.keepWhiteOverlays'] ?? false;
        $deactivateAutoDetect = $config[$domain . '.deactivateAutoDetect'] ?? false;
        $useHslVariables = $config[$domain . '.useHslVariables'] ?? false;

        if (!$invertBlackShadows) {
            $css = preg_replace_callback('/box-shadow:(.*)#(0{3})/', function (array $matches): string {
                return str_replace('#000', sprintf('hsl(%sdeg, %s%%, %s%%)', ...$this->hex2hsl('#000')), $matches[0]);
            }, $css);
        }

        if (!$keepWhiteOverlays) {
            $css = preg_replace_callback('/(background|background-color):(.*)rgba\(255, 255, 255, (.*)\)/', function (array $matches) use ($useHslVariables): string {
                $rgba = sprintf('rgba(255, 255, 255, %s)', $matches[3]);
                $replace = $useHslVariables
                    ? sprintf('hsla(var(--color-fff), %s)', $matches[3])
                    : sprintf('linear-gradient(to bottom, var(--color-fff) calc((%1$s - 1) * 10000%%), transparent calc(%1$s * 10000%%))', $matches[3]);

                return str_replace($rgba, $replace, $matches[0]);
            }, $css);
        }

        preg_match_all('/#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})/', $css, $matches);
        $hexColors = array_unique($matches[0] ?? []);
        $lightColors = $darkColors = [];

        foreach ($hexColors as $hexColor) {
            if (in_array($hexColor, $ignoredHexCodes, true)) {
                continue;
            }

            [$hue, $saturation, $lightness] = $this->hex2hsl($hexColor);

            $naturalSaturation = max($saturation - abs($lightness - 50), 0);
            if ($naturalSaturation > $saturationThreshold) {
                continue;
            }

            $variable = sprintf('--color-%s', ltrim($hexColor, '#'));
            $css = str_replace($hexColor, $useHslVariables ? sprintf('hsl(var(%s))', $variable) : sprintf('var(%s)', $variable), $css);

            $lightColors[] = $useHslVariables
                ? sprintf('%s: %sdeg, %s%%, %s%%', $variable, $hue, $saturation, $lightness)
                : sprintf('%s: %s', $variable, $hexColor);

            $lightnessIncrement = ($lightness / 100) * $minLightness;
            $lightness = min(100 - $lightness + $lightnessIncrement, 100);

            $darkColors[] = $useHslVariables
                ? sprintf('%s: %sdeg, %s%%, %s%%', $variable, $hue, $saturation, $lightness)
                : sprintf('%s: %s', $variable, $this->hsl2hex($hue, $saturation, $lightness));
        }

        if (empty($darkColors)) {
            return;
        }

        $css .= PHP_EOL . sprintf(':root { %s }', implode('; ', $lightColors)) . PHP_EOL;
        $css .= sprintf(':root[data-theme="dark"] { %s }', implode('; ', $darkColors));

        if (!$deactivateAutoDetect) {
            $css .= PHP_EOL . sprintf('@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { %s } }', implode('; ', $darkColors));
        }

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

    private function hsl2hex(float $h, float $s, float $l): string
    {
        $h /= 360;
        $s /= 100;
        $l /= 100;

        $r = $g = $b = $l;

        $v = ($l <= 0.5) ? ($l * (1.0 + $s)) : ($l + $s - $l * $s);
        if ($v > 0) {
            $m = $l + $l - $v;
            $sv = ($v - $m) / $v;
            $h *= 6.0;
            $sextant = floor($h);
            $fract = $h - $sextant;
            $vsf = $v * $sv * $fract;
            $mid1 = $m + $vsf;
            $mid2 = $v - $vsf;

            switch ($sextant) {
                case 0:
                    $r = $v;
                    $g = $mid1;
                    $b = $m;

                    break;
                case 1:
                    $r = $mid2;
                    $g = $v;
                    $b = $m;

                    break;
                case 2:
                    $r = $m;
                    $g = $v;
                    $b = $mid1;

                    break;
                case 3:
                    $r = $m;
                    $g = $mid2;
                    $b = $v;

                    break;
                case 4:
                    $r = $mid1;
                    $g = $m;
                    $b = $v;

                    break;
                case 5:
                    $r = $v;
                    $g = $m;
                    $b = $mid2;

                    break;
            }
        }

        $r = (int) ($r * 255);
        $g = (int) ($g * 255);
        $b = (int) ($b * 255);

        $r = ($r <= 15) ? '0' . dechex($r) : dechex($r);
        $g = ($g <= 15) ? '0' . dechex($g) : dechex($g);
        $b = ($b <= 15) ? '0' . dechex($b) : dechex($b);

        if ($r[0] === $r[1] && $g[0] === $g[1] && $b[0] === $b[1]) {
            return '#' . $r[0] . $g[0] . $b[0];
        }

        return '#' . $r . $g . $b;
    }
}
