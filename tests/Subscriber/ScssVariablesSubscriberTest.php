<?php declare(strict_types=1);

namespace Dne\StorefrontDarkMode\Test\Subscriber;

use Dne\StorefrontDarkMode\Subscriber\ScssVariablesSubscriber;
use Generator;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Theme\Event\ThemeCopyToLiveEvent;
use const DIRECTORY_SEPARATOR;

class ScssVariablesSubscriberTest extends TestCase
{
    /**
     * @var MockObject|Filesystem
     */
    private $themeFilesystem;

    /**
     * @var MockObject|SystemConfigService
     */
    private $configService;

    private ScssVariablesSubscriber $subscriber;

    public function setUp(): void
    {
        $this->themeFilesystem = $this->createMock(Filesystem::class);
        $this->configService = $this->createMock(SystemConfigService::class);

        $this->subscriber = new ScssVariablesSubscriber(
            $this->themeFilesystem,
            $this->configService
        );
    }

    /**
     * @dataProvider configCases
     */
    public function testListenToOnThemeCopyToLive(array $config, string $css, string $expected): void
    {
        $event = new ThemeCopyToLiveEvent('', '', '', 'foobar');

        $path = 'foobar' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'all.css';
        $this->themeFilesystem->expects(static::once())->method('has')->with($path)->willReturn(true);

        $this->themeFilesystem->expects(static::once())->method('read')->with($path)->willReturn($css);
        $this->configService->expects(static::once())->method('getDomain')->willReturn($config);

        $this->themeFilesystem->expects(static::once())->method('put')->with($path, $expected);

        $this->subscriber->onThemeCopyToLive($event);
    }

    public function configCases(): Generator
    {
        $domain = 'DneStorefrontDarkMode.config.';

        yield 'default config / hex' => [
            [],
            <<<EOF
.black { color: #000; }
.white { color: #fff; }
.green { color: #002200; }
EOF,
            <<<EOF
.black { color: var(--color-000); }
.white { color: var(--color-fff); }
.green { color: #002200; }
:root { --color-000: #000; --color-fff: #fff }
:root[data-theme="dark"] { --color-000: #fff; --color-fff: #262626 }
@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { --color-000: #fff; --color-fff: #262626 } }
EOF
        ];

        yield 'default config / hsl' => [
            [$domain . 'useHslVariables' => true],
            <<<EOF
.black { color: #000; }
.white { color: #fff; }
.green { color: #002200; }
EOF,
            <<<EOF
.black { color: hsl(var(--color-000)); }
.white { color: hsl(var(--color-fff)); }
.green { color: #002200; }
:root { --color-000: 0deg, 0%, 0%; --color-fff: 0deg, 0%, 100% }
:root[data-theme="dark"] { --color-000: 0deg, 0%, 100%; --color-fff: 0deg, 0%, 15% }
@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { --color-000: 0deg, 0%, 100%; --color-fff: 0deg, 0%, 15% } }
EOF
        ];

        yield 'min lightness / saturation threshold / hsl' => [
            [$domain . 'useHslVariables' => true, $domain . 'minLightness' => 30, $domain . 'saturationThreshold' => 70],
            <<<EOF
.black { color: #000; }
.white { color: #fff; }
.green { color: #002200; }
EOF,
            <<<EOF
.black { color: hsl(var(--color-000)); }
.white { color: hsl(var(--color-fff)); }
.green { color: hsl(var(--color-002200)); }
:root { --color-000: 0deg, 0%, 0%; --color-fff: 0deg, 0%, 100%; --color-002200: 120deg, 100%, 7% }
:root[data-theme="dark"] { --color-000: 0deg, 0%, 100%; --color-fff: 0deg, 0%, 30%; --color-002200: 120deg, 100%, 95.1% }
@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { --color-000: 0deg, 0%, 100%; --color-fff: 0deg, 0%, 30%; --color-002200: 120deg, 100%, 95.1% } }
EOF
        ];

        yield 'deactivate auto detetion / hsl' => [
            [$domain . 'useHslVariables' => true, $domain . 'deactivateAutoDetect' => true],
            <<<EOF
.black { color: #000; }
EOF,
            <<<EOF
.black { color: hsl(var(--color-000)); }
:root { --color-000: 0deg, 0%, 0% }
:root[data-theme="dark"] { --color-000: 0deg, 0%, 100% }
EOF
        ];
    }
}
