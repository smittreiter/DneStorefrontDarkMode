<?php declare(strict_types=1);

namespace Dne\StorefrontDarkMode\Subscriber;

use Dne\StorefrontDarkMode\Subscriber\Data\CssColors;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;
use Sabberworm\CSS\CSSList\AtRuleBlockList;
use Sabberworm\CSS\OutputFormat;
use Sabberworm\CSS\Parser;
use Sabberworm\CSS\Parsing\SourceException;
use Sabberworm\CSS\Parsing\UnexpectedTokenException;
use Sabberworm\CSS\Rule\Rule;
use Sabberworm\CSS\RuleSet\DeclarationBlock;
use Sabberworm\CSS\Value\Color;
use Sabberworm\CSS\Value\CSSFunction;
use Sabberworm\CSS\Value\RuleValueList;
use Sabberworm\CSS\Value\Size;
use Sabberworm\CSS\Value\ValueList;
use Shopware\Core\Framework\Plugin\Event\PluginPreDeactivateEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Event\ThemeCompilerConcatenatedStylesEvent;
use Shopware\Storefront\Theme\Event\ThemeCopyToLiveEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Service\ResetInterface;
use const DIRECTORY_SEPARATOR;

class ThemeCompileSubscriber implements EventSubscriberInterface, ResetInterface
{
    private const DEFAULT_MIN_LIGHTNESS = 15;
    private const DEFAULT_SATURATION_THRESHOLD = 65;

    private Filesystem $themeFilesystem;

    private SystemConfigService $configService;

    private bool $debug;

    private bool $disabled = false;

    private ?string $currentSalesChannelId = null;

    public function __construct(Filesystem $themeFilesystem, SystemConfigService $configService, bool $debug = true)
    {
        $this->themeFilesystem = $themeFilesystem;
        $this->configService = $configService;
        $this->debug = $debug;
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
        $config = [
            'saturationThreshold' => $config[$domain . '.saturationThreshold'] ?? self::DEFAULT_SATURATION_THRESHOLD,
            'minLightness' => $config[$domain . '.minLightness'] ?? self::DEFAULT_MIN_LIGHTNESS,
            'grayscaleTint' => !empty($config[$domain . '.grayscaleTint']) ? current($this->hex2hsl($config[$domain . '.grayscaleTint'])) : null,
            'grayscaleTintAmount' => $config[$domain . '.grayscaleTintAmount'] ?? 0,
            'ignoredHexCodes' => explode(',', str_replace(' ', '', strtolower($config[$domain . '.ignoredHexCodes'] ?? ''))),
            'invertShadows' => $config[$domain . '.invertShadows'] ?? false,
            'deactivateAutoDetect' => $config[$domain . '.deactivateAutoDetect'] ?? false,
            'useHslVariables' => $config[$domain . '.useHslVariables'] ?? false,
            'keepNamedColors' => $config[$domain . '.keepNamedColors'] ?? false,
        ];

        try {
            $document = (new Parser($css))->parse();
        } catch (SourceException) {
            return;
        }

        $lightColors = $darkColors = [];

        foreach ($document->getAllRuleSets() as $ruleSet) {
            $newRuleset = clone $ruleSet;
            foreach ($newRuleset->getRules() as $rule) {
                if ((!$config['invertShadows'] && $rule->getRule() === 'box-shadow') || str_ends_with($rule->getRule(), '-immutable')) {
                    continue;
                }

                $value = $rule->getValue();

                if ($value instanceof Color && $color = $this->handleColorValue($lightColors, $darkColors, $value, $config)) {
                    $rule->setValue($color);

                    continue;
                }

                if (!$config['keepNamedColors'] && is_string($value) && $color = $this->handleColorName($lightColors, $darkColors, $value, $config)) {
                    $rule->setValue($color);

                    continue;
                }

                if ($value instanceof Color || (!$value instanceof RuleValueList && !$value instanceof CSSFunction)) {
                    continue;
                }

                $this->handleValueList($lightColors, $darkColors, $value, $config);
            }
            $document->replace($ruleSet, $newRuleset);
        }

        if (empty($darkColors)) {
            return;
        }

        try {
            $root = new DeclarationBlock();
            $root->setSelectors(':root');
            foreach ($lightColors as $variable => $value) {
                $rule = new Rule($variable);
                $rule->setValue($value);
                $root->addRule($rule);
            }
            $document->append($root);

            $rootDark = new DeclarationBlock();
            $rootDark->setSelectors(':root[data-theme="dark"]');
            foreach ($darkColors as $variable => $value) {
                $rule = new Rule($variable);
                $rule->setValue($value);
                $rootDark->addRule($rule);
            }
            $document->append($rootDark);

            if (!$config['deactivateAutoDetect']) {
                $mediaBlock = new AtRuleBlockList('media (prefers-color-scheme: dark)');
                $rootDark = clone $rootDark;
                $rootDark->setSelectors(':root:not([data-theme="light"])');
                $mediaBlock->append($rootDark);
                $document->append($mediaBlock);
            }
        } catch (UnexpectedTokenException) {
            return;
        }

        $css = $document->render($this->debug ? OutputFormat::createPretty() : OutputFormat::createCompact());

        $this->themeFilesystem->put($cssPath, $css);
    }

    private function handleValueList(array &$lightColors, array &$darkColors, ValueList $valueList, array $config): ValueList
    {
        $components = $valueList->getListComponents();

        if (!is_array($components)) {
            return $valueList;
        }

        $newComponents = [];
        foreach ($components as $component) {
            if ($component instanceof Color && $color = $this->handleColorValue($lightColors, $darkColors, $component, $config)) {
                $newComponents[] = $color;

                continue;
            }

            if (!$config['keepNamedColors'] && is_string($component) && $color = $this->handleColorName($lightColors, $darkColors, $component, $config)) {
                $newComponents[] = $color;

                continue;
            }

            if ($component instanceof RuleValueList || $component instanceof CSSFunction) {
                $newComponents[] = $this->handleValueList($lightColors, $darkColors, $component, $config);

                continue;
            }

            $newComponents[] = $component;
        }

        $valueList->setListComponents($newComponents);

        return $valueList;
    }

    private function handleColorValue(array &$lightColors, array &$darkColors, Color $color, array $config): ?RuleValueList
    {
        $components = $color->getListComponents();
        $r = $components['r'] ?? null;
        $g = $components['g'] ?? null;
        $b = $components['b'] ?? null;
        $a = $components['a'] ?? null;

        if (!$r instanceof Size || !$g instanceof Size || !$b instanceof Size) {
            return null;
        }

        if ($a instanceof Size) {
            [$hue, $saturation, $lightness] = $this->rgb2hsl($r->getSize(), $g->getSize(), $b->getSize());

            if ($a->getSize() <= 0.5 && $lightness < 50) {
                return null;
            }

            $variable = sprintf('--color-rgb-%s-%s-%s', $r->getSize(), $g->getSize(), $b->getSize());
            $lightColors[$variable] = sprintf('%sdeg,%s%%,%s%%', $hue, $saturation, $lightness);

            [$hue, $saturation, $lightness] = $this->darken($hue, $saturation, $lightness, $config);

            $darkColors[$variable] = sprintf('%sdeg,%s%%,%s%%', $hue, $saturation, $lightness);

            $valueList = new RuleValueList();
            $valueList->addListComponent(sprintf('hsla(var(%s),%s)', $variable, $a->getSize()));

            return $valueList;
        }

        $hex = sprintf('#%02x%02x%02x', $r->getSize(), $g->getSize(), $b->getSize());
        if ($hex[1] === $hex[2] && $hex[3] === $hex[4] && $hex[5] === $hex[6]) {
            $hex = '#' . $hex[1] . $hex[3] . $hex[5];
        }

        if (in_array($hex, $config['ignoredHexCodes'], true)) {
            return null;
        }

        return $this->setVariablesFromHex($lightColors, $darkColors, $hex, $config);
    }

    private function handleColorName(array &$lightColors, array &$darkColors, string $color, array $config): ?RuleValueList
    {
        $colors = CssColors::MAPPINGS;

        if (!in_array(strtolower($color), array_keys($colors), true)) {
            return null;
        }

        $hexColor = $colors[strtolower($color)];

        return $this->setVariablesFromHex($lightColors, $darkColors, $hexColor, $config);
    }

    private function setVariablesFromHex(array &$lightColors, array &$darkColors, string $hexColor, array $config): ?RuleValueList
    {
        [$hue, $saturation, $lightness] = $this->hex2hsl($hexColor);

        $naturalSaturation = max($saturation - abs($lightness - 50), 0);
        if ($naturalSaturation > $config['saturationThreshold']) {
            return null;
        }

        $variable = sprintf('--color-%s', ltrim($hexColor, '#'));

        $lightColors[$variable] = $config['useHslVariables']
            ? sprintf('%sdeg,%s%%,%s%%', $hue, $saturation, $lightness)
            : $hexColor;

        [$hue, $saturation, $lightness] = $this->darken($hue, $saturation, $lightness, $config);

        $darkColors[$variable] = $config['useHslVariables']
            ? sprintf('%sdeg,%s%%,%s%%', $hue, $saturation, $lightness)
            : $this->hsl2hex($hue, $saturation, $lightness);

        $valueList = new RuleValueList();
        $valueList->addListComponent($config['useHslVariables'] ? sprintf('hsl(var(%s))', $variable) : sprintf('var(%s)', $variable));

        return $valueList;
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
        $lightnessIncrement = ($lightness / 100) * $config['minLightness'];
        $lightness = min(100 - $lightness + $lightnessIncrement, 100);

        if ($config['grayscaleTint'] !== null && $config['grayscaleTintAmount'] && ($hue + $saturation) === 0.0) {
            $hue = $config['grayscaleTint'];
            $saturation = min($saturation + $config['grayscaleTintAmount'], 100);
        }

        return [$hue, $saturation, $lightness];
    }
}
