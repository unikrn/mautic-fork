<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Digital Media Solutions, LLC
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticExtendedFieldBundle\EventListener;

use Mautic\ConfigBundle\ConfigEvents;
use Mautic\ConfigBundle\Event\ConfigBuilderEvent;
use Mautic\CoreBundle\EventListener\CommonSubscriber;

/**
 * Class ConfigSubscriber.
 */
class ConfigSubscriber extends CommonSubscriber
{
    /**
     * @var
     */
    protected $event;

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        $eventList = [
            ConfigEvents::CONFIG_ON_GENERATE       => ['onConfigGenerate', 0],
        ];

        return $eventList;
    }

    /**
     * @param ConfigBuilderEvent $event
     */
    public function onConfigGenerate(ConfigBuilderEvent $event)
    {
        $params = !empty(
        $event->getParametersFromConfig(
            'MauticExtendedFieldBundle'
        )
        ) ? $event->getParametersFromConfig('MauticExtendedFieldBundle') : [];
        $event->addForm(
            [
                'bundle'     => 'MauticExtendedFieldBundle',
                'formAlias'  => 'extendedField_config',
                'formTheme'  => 'MauticExtendedFieldBundle:Config',
                'parameters' => $params,
            ]
        );
    }
}
