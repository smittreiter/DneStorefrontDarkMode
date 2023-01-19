<?php declare(strict_types=1);

namespace Dne\StorefrontDarkMode\Test\Subscriber;

use Dne\StorefrontDarkMode\Subscriber\ThemeCompileSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Theme\AbstractCompilerConfiguration;
use Shopware\Storefront\Theme\AbstractScssCompiler;

class ThemeCompileSubscriberTest extends TestCase
{
    /**
     * @var MockObject|AbstractScssCompiler
     */
    private $decorated;

    /**
     * @var MockObject|SystemConfigService
     */
    private $configService;

    private ThemeCompileSubscriber $subscriber;

    public function setUp(): void
    {
        $this->decorated = $this->createMock(AbstractScssCompiler::class);
        $this->configService = $this->createMock(SystemConfigService::class);

        $this->subscriber = new ThemeCompileSubscriber(
            $this->decorated,
            $this->configService
        );
    }

    /**
     * @dataProvider configCases
     */
    public function testListenToOnThemeCopyToLive(array $config, string $css, string $expected): void
    {
        $this->decorated->expects(static::once())->method('compileString')->willReturn($css);
        $this->configService->expects(static::once())->method('getDomain')->willReturn($config);

        $actual = $this->subscriber->compileString($this->createMock(AbstractCompilerConfiguration::class), '');

        static::assertEquals($expected, $actual);
    }

    public function configCases(): iterable
    {
        $domain = 'DneStorefrontDarkMode.config.';

        yield 'default config / hex' => [
            [],
            <<<EOF
.black { color: #000; }
.white { color: #fff; }
.green { color: #005500; }
EOF,
            <<<EOF

.black {
	color: var(--color-000);
}

.white {
	color: var(--color-fff);
}

.green {
	color: #050;
}

:root {
	--color-000: #000;
	--color-fff: #fff;
}

:root[data-theme="dark"] {
	--color-000: #fff;
	--color-fff: #262626;
}

@media (prefers-color-scheme: dark) {
	:root:not([data-theme="light"]) {
		--color-000: #fff;
		--color-fff: #262626;
	}
}

EOF
        ];

        yield 'default config / hsl' => [
            [$domain . 'useHslVariables' => true],
            <<<EOF
.black { color: #000; }
.white { color: #fff; }
.green { color: #005500; }
EOF,
            <<<EOF

.black {
	color: hsl(var(--color-000));
}

.white {
	color: hsl(var(--color-fff));
}

.green {
	color: #050;
}

:root {
	--color-000: 0deg,0%,0%;
	--color-fff: 0deg,0%,100%;
}

:root[data-theme="dark"] {
	--color-000: 0deg,0%,100%;
	--color-fff: 0deg,0%,15%;
}

@media (prefers-color-scheme: dark) {
	:root:not([data-theme="light"]) {
		--color-000: 0deg,0%,100%;
		--color-fff: 0deg,0%,15%;
	}
}

EOF
        ];

        yield 'default config / convert rgba' => [
            [],
            <<<EOF
.white { color: rgba(255,255,255, 0.75); }
EOF,
            <<<EOF

.white {
	color: hsla(var(--color-rgb-255-255-255),0.75);
}

:root {
	--color-rgb-255-255-255: 0deg,0%,100%;
}

:root[data-theme="dark"] {
	--color-rgb-255-255-255: 0deg,0%,15%;
}

@media (prefers-color-scheme: dark) {
	:root:not([data-theme="light"]) {
		--color-rgb-255-255-255: 0deg,0%,15%;
	}
}

EOF
        ];

        yield 'default config / convert color names' => [
            [],
            <<<EOF
.black { color: black; background-color: red; background-image: linear-gradient(white, black); }
.white { border: 10px White solid; font-family: 'The Black Font'; white-space: var(--white-space); }
EOF,
            <<<EOF

.black {
	color: var(--color-000);
	background-color: red;
	background-image: linear-gradient(var(--color-fff), var(--color-000));
}

.white {
	border: 10px var(--color-fff) solid;
	font-family: "The Black Font";
	white-space: var(--white-space);
}

:root {
	--color-000: #000;
	--color-fff: #fff;
}

:root[data-theme="dark"] {
	--color-000: #fff;
	--color-fff: #262626;
}

@media (prefers-color-scheme: dark) {
	:root:not([data-theme="light"]) {
		--color-000: #fff;
		--color-fff: #262626;
	}
}

EOF
        ];

        yield 'default config / keep immutable css variables' => [
            [],
            <<<EOF
.black { color: #000; }
:root { --white-immutable: #fff; --black-immutable:rgba(255, 255, 255, 1) }
EOF,
            <<<EOF

.black {
	color: var(--color-000);
}

:root {
	--white-immutable: #fff;
	--black-immutable: rgba(255, 255, 255, 1);
}

:root {
	--color-000: #000;
}

:root[data-theme="dark"] {
	--color-000: #fff;
}

@media (prefers-color-scheme: dark) {
	:root:not([data-theme="light"]) {
		--color-000: #fff;
	}
}

EOF
        ];

        yield 'default config / keep box shadow' => [
            [],
            <<<EOF
.black { color: #000; }
.shadow-hex { box-shadow: 10px 5px 5px #000; }
.shadow-rgb { box-shadow: 10px 5px 5px rgba(0, 0, 0, 0.5); }
EOF,
            <<<EOF

.black {
	color: var(--color-000);
}

.shadow-hex {
	box-shadow: 10px 5px 5px #000;
}

.shadow-rgb {
	box-shadow: 10px 5px 5px rgba(0, 0, 0, .5);
}

:root {
	--color-000: #000;
}

:root[data-theme="dark"] {
	--color-000: #fff;
}

@media (prefers-color-scheme: dark) {
	:root:not([data-theme="light"]) {
		--color-000: #fff;
	}
}

EOF
        ];

        yield 'min lightness / saturation threshold / hsl' => [
            [$domain . 'useHslVariables' => true, $domain . 'minLightness' => 30, $domain . 'saturationThreshold' => 70],
            <<<EOF
.black { color: #000; }
.white { color: #fff; }
.green { color: #002500; }
EOF,
            <<<EOF

.black {
	color: hsl(var(--color-000));
}

.white {
	color: hsl(var(--color-fff));
}

.green {
	color: hsl(var(--color-002500));
}

:root {
	--color-000: 0deg,0%,0%;
	--color-fff: 0deg,0%,100%;
	--color-002500: 120deg,100%,7%;
}

:root[data-theme="dark"] {
	--color-000: 0deg,0%,100%;
	--color-fff: 0deg,0%,30%;
	--color-002500: 120deg,100%,95.1%;
}

@media (prefers-color-scheme: dark) {
	:root:not([data-theme="light"]) {
		--color-000: 0deg,0%,100%;
		--color-fff: 0deg,0%,30%;
		--color-002500: 120deg,100%,95.1%;
	}
}

EOF
        ];

        yield 'grayscale tinting / hsl' => [
            [$domain . 'useHslVariables' => true, $domain . 'grayscaleTint' => '#0000ff', $domain . 'grayscaleTintAmount' => 5],
            <<<EOF
.black { color: #000; }
.white { color: #fff; }
EOF,
            <<<EOF

.black {
	color: hsl(var(--color-000));
}

.white {
	color: hsl(var(--color-fff));
}

:root {
	--color-000: 0deg,0%,0%;
	--color-fff: 0deg,0%,100%;
}

:root[data-theme="dark"] {
	--color-000: 240deg,5%,100%;
	--color-fff: 240deg,5%,15%;
}

@media (prefers-color-scheme: dark) {
	:root:not([data-theme="light"]) {
		--color-000: 240deg,5%,100%;
		--color-fff: 240deg,5%,15%;
	}
}

EOF
        ];

        yield 'deactivate auto detetion / hsl' => [
            [$domain . 'useHslVariables' => true, $domain . 'deactivateAutoDetect' => true],
            <<<EOF
.black { color: #000; }
EOF,
            <<<EOF

.black {
	color: hsl(var(--color-000));
}

:root {
	--color-000: 0deg,0%,0%;
}

:root[data-theme="dark"] {
	--color-000: 0deg,0%,100%;
}

EOF
        ];

        yield 'ignored selectors and hex codes / hex' => [
            [$domain . 'ignoredHexCodes' => ['#fff'], $domain . 'ignoredSelectors' => ['.my-class']],
            <<<EOF
.black { color: #000; }
.foo { color: #fff; }
.my-class { color: #010101; }
EOF,
            <<<EOF

.black {
	color: var(--color-000);
}

.foo {
	color: #fff;
}

.my-class {
	color: #010101;
}

:root {
	--color-000: #000;
}

:root[data-theme="dark"] {
	--color-000: #fff;
}

@media (prefers-color-scheme: dark) {
	:root:not([data-theme="light"]) {
		--color-000: #fff;
	}
}

EOF
        ];
    }
}
