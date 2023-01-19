<?php declare(strict_types=1);

namespace Dne\StorefrontDarkMode\Subscriber;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Shopware\Storefront\Theme\ThemeCollection;
use Shopware\Storefront\Theme\ThemeService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Service\ResetInterface;

class SystemConfigChangedSubscriber implements EventSubscriberInterface, ResetInterface
{
    private ThemeService $themeService;

    /**
     * @var EntityRepository
     */
    private $salesChannelRepository;

    private EventDispatcherInterface $dispatcher;

    private bool $compileAll = false;

    private array $compileSalesChannelIds = [];

    /**
     * @param EntityRepository $salesChannelRepository
     */
    public function __construct(ThemeService $themeService, $salesChannelRepository, EventDispatcherInterface $dispatcher)
    {
        $this->themeService = $themeService;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->dispatcher = $dispatcher;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SystemConfigChangedEvent::class => 'onSystemConfigChanged',
        ];
    }

    public function reset()
    {
        $this->compileAll = false;
        $this->compileSalesChannelIds = [];
    }

    public function onSystemConfigChanged(SystemConfigChangedEvent $event): void
    {
        if ($this->compileAll || !str_starts_with($event->getKey(), 'DneStorefrontDarkMode')) {
            return;
        }

        if (!empty($this->compileSalesChannelIds)) {
            if ($event->getSalesChannelId()) {
                $this->compileSalesChannelIds[] = $event->getSalesChannelId();

                return;
            }

            $this->compileAll = true;

            return;
        }

        if ($event->getSalesChannelId()) {
            $this->compileSalesChannelIds[] = $event->getSalesChannelId();
        } else {
            $this->compileAll = true;
        }

        $this->dispatcher->addListener('kernel.response', [$this, 'compile']);
    }

    public function compile(): void
    {
        if (!$this->compileAll && empty($this->compileSalesChannelIds)) {
            return;
        }

        $context = Context::createDefaultContext();

        $salesChannels = $this->getSalesChannels($context);
        foreach ($salesChannels as $salesChannel) {
            /** @var ThemeCollection|null $themes */
            $themes = $salesChannel->getExtensionOfType('themes', ThemeCollection::class);
            if (!$themes || !$theme = $themes->first()) {
                continue;
            }

            $this->themeService->compileTheme($salesChannel->getId(), $theme->getId(), $context);
        }
    }

    private function getSalesChannels(Context $context): SalesChannelCollection
    {
        $criteria = new Criteria();
        $criteria->addAssociation('themes');

        if ($this->compileAll === false) {
            $criteria->addFilter(new EqualsAnyFilter('id', $this->compileSalesChannelIds));
        }

        /** @var SalesChannelCollection $result */
        $result = $this->salesChannelRepository->search($criteria, $context)->getEntities();

        return $result;
    }
}
