<?php

/*
 * @copyright   2019 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\PageBundle\Model;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\LeadBundle\Tracker\ContactTracker;
use Mautic\PageBundle\Entity\Page;
use Mautic\PageBundle\Entity\Redirect;
use Symfony\Component\HttpFoundation\Request;

class Tracking404Model
{
    /**
     * @var ContactTracker
     */
    private $contactTracker;

    /**
     * @var PageModel
     */
    private $pageModel;

    /**
     * Tracking404Model constructor.
     *
     * @param CoreParametersHelper $coreParametersHelper
     * @param ContactTracker       $contactTracker
     * @param PageModel            $pageModel
     */
    public function __construct(
        CoreParametersHelper $coreParametersHelper,
        ContactTracker $contactTracker,
        PageModel $pageModel
    ) {
        $this->coreParametersHelper = $coreParametersHelper;
        $this->contactTracker       = $contactTracker;
        $this->pageModel            = $pageModel;
    }

    /**
     * @param Page|Redirect $entity
     * @param Request       $request
     *
     * @throws \Exception
     */
    public function hitPage($entity, Request $request)
    {
        $this->pageModel->hitPage($entity, $request, 404);
    }

    /**
     * @return bool
     */
    public function isTrackable()
    {
        if (!$this->coreParametersHelper->getParameter('disable_tracking_404_anonymous')) {
            return true;
        }
        // already tracked and identified contact
        if ($lead = $this->contactTracker->getContactByTrackedDevice()) {
            if (!$lead->isAnonymous()) {
                return true;
            }
        }

        return false;
    }
}
