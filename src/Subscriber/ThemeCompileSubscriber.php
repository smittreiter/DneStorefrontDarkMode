<?php declare(strict_types=1);

namespace Dne\StorefrontDarkMode\Subscriber;

use Dne\StorefrontDarkMode\Subscriber\Data\CssColors;
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

class ThemeCompileSubscriber implements EventSubscriberInterface, ResetInterface
{
    private const DEFAULT_MIN_LIGHTNESS = 15;
    private const DEFAULT_SATURATION_THRESHOLD = 65;

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
        $saturationThreshold = $config[$domain . '.saturationThreshold'] ?? self::DEFAULT_SATURATION_THRESHOLD;
        $ignoredHexCodes = explode(',', str_replace(' ', '', strtolower($config[$domain . '.ignoredHexCodes'] ?? '')));
        $invertShadows = $config[$domain . '.invertShadows'] ?? false;
        $deactivateAutoDetect = $config[$domain . '.deactivateAutoDetect'] ?? false;
        $useHslVariables = $config[$domain . '.useHslVariables'] ?? false;
        $keepNamedColors = $config[$domain . 'keepNamedColors'] ?? false;

        if (!$keepNamedColors) {
            $css = $this->convertColorNames($css);
        }

        if (!$invertShadows) {
            $css = $this->conserveShadows($css);
        }

        $lightColors = $darkColors = [];

        // remove whitespaces before values of immutable variables to be matchable by negative lookbehind
        $css = preg_replace('/-immutable:\s+/','-immutable:', $css);

        $css = preg_replace_callback(
            '/(?<!-immutable:)rgba\((.*?),(.*?),(.*?),(.*?)\)/',
            function (array $matches) use (&$lightColors, &$darkColors, $config): string {
                [$original, $r, $g, $b, $a] = $matches;

                [$hue, $saturation, $lightness] = $this->rgb2hsl((float) $r, (float) $g, (float) $b);

                if ((float) $a <= 0.5 && $lightness < 50) {
                    return $original;
                }

                $variable = sprintf('--color-rgb-%s-%s-%s', trim($r), trim($g), trim($b));
                $lightColors[] = sprintf('%s: %sdeg, %s%%, %s%%', $variable, $hue, $saturation, $lightness);

                [$hue, $saturation, $lightness] = $this->darken($hue, $saturation, $lightness, $config);

                $darkColors[] = sprintf('%s: %sdeg, %s%%, %s%%', $variable, $hue, $saturation, $lightness);

                return sprintf('hsla(var(%s), %s)', $variable, trim($a));
            },
            $css
        );

        preg_match_all('/(?<!-immutable:)#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})/', $css, $matches);
        $hexColors = array_unique($matches[0] ?? []);

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

            [$hue, $saturation, $lightness] = $this->darken($hue, $saturation, $lightness, $config);

            $darkColors[] = $useHslVariables
                ? sprintf('%s: %sdeg, %s%%, %s%%', $variable, $hue, $saturation, $lightness)
                : sprintf('%s: %s', $variable, $this->hsl2hex($hue, $saturation, $lightness));
        }

        if (empty($darkColors)) {
            return;
        }

        $lightColors = array_unique($lightColors);
        $darkColors = array_unique($darkColors);

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
        $r = hexdec($hexstr[0] . $hexstr[1]);
        $g = hexdec($hexstr[2] . $hexstr[3]);
        $b = hexdec($hexstr[4] . $hexstr[5]);

        return $this->rgb2hsl((float) $r, (float) $g, (float) $b);
    }

    private function rgb2hsl(float $r, float $g, float $b): array
    {
        $r /= 255;
        $g /= 255;
        $b /= 255;

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

    private function darken(float $hue, float $saturation, float $lightness, array $config): array
    {
        $domain = 'DneStorefrontDarkMode.config';
        $minLightness = $config[$domain . '.minLightness'] ?? self::DEFAULT_MIN_LIGHTNESS;
        $grayscaleTint = !empty($config[$domain . '.grayscaleTint'])
            ? current($this->hex2hsl($config[$domain . '.grayscaleTint']))
            : null;
        $grayscaleTintAmount = $config[$domain . '.grayscaleTintAmount'] ?? 0;

        $lightnessIncrement = ($lightness / 100) * $minLightness;
        $lightness = min(100 - $lightness + $lightnessIncrement, 100);

        if ($grayscaleTint !== null && $grayscaleTintAmount && ($hue + $saturation) === 0.0) {
            $hue = $grayscaleTint;
            $saturation = min($saturation + $grayscaleTintAmount, 100);
        }

        return [$hue, $saturation, $lightness];
    }

    private function conserveShadows(string $css): string
    {
        $css = preg_replace_callback('/box-shadow:([^;]*)#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})(.*?);/', function (array $matches): string {
            $search = sprintf('#%s', $matches[2]);

            return str_replace($search, sprintf('hsl(%sdeg, %s%%, %s%%)', ...$this->hex2hsl($search)), $matches[0]);
        }, $css);

        return preg_replace_callback('/box-shadow:([^;]*)rgba\((.*?),(.*?),(.*?),(.*?)\)(.*?);/', function (array $matches): string {
            [,, $r, $g, $b, $a] = $matches;
            $hsla = $this->rgb2hsl((float) $r, (float) $g, (float) $b);
            $hsla[] = trim($a);
            $search = sprintf('rgba(%s,%s,%s,%s)', $r, $g, $b, $a);

            return str_replace($search, sprintf('hsla(%sdeg, %s%%, %s%%, %s)', ...$hsla), $matches[0]);
        }, $css);
    }

    private function convertColorNames(string $css): string
    {
        $colors = CssColors::MAPPINGS;

        return preg_replace_callback(
            '/:(.*?)(' . implode('|', array_keys($colors)) . ')( +|;)/i',
            function (array $matches) use ($colors): string {
                [$original,, $color] = $matches;

                if (!\array_key_exists(strtolower($color), $colors)) {
                    return $original;
                }

                return str_replace($color, $colors[strtolower($color)], $original);
            },
            $css
        );
    }
}
