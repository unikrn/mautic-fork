<?php
/**
 * @package     ThirdSetMauticTimingBundle
 * @copyright   2018 Third Set Productions.
 * @author      Third Set Productions
 * @link        http://www.thirdset.com
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\ThirdSetMauticTimingBundle\Executioner;

use Doctrine\Common\Collections\ArrayCollection;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Executioner\ContactFinder\KickoffContactFinder;
use Mautic\CampaignBundle\Executioner\ContactFinder\Limiter\ContactLimiter;
use Mautic\CampaignBundle\Executioner\Exception\NoContactsFoundException;
use Mautic\CampaignBundle\Executioner\Exception\NoEventsFoundException;
use Mautic\CampaignBundle\Executioner\Result\Counter;
use Mautic\CampaignBundle\Executioner\Scheduler\EventScheduler;
use Mautic\CampaignBundle\Executioner\Scheduler\Exception\NotSchedulableException;
use Mautic\CoreBundle\Helper\ProgressBarHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * Older versions of Mautic don't have the KickoffExecutioner class so we extend
 * a middleman class to maintain backwards compatibility.
 */
if (class_exists('\Mautic\CampaignBundle\Executioner\KickoffExecutioner')) {
    class VersionSafeKickoffExecutioner extends \Mautic\CampaignBundle\Executioner\KickoffExecutioner {}
} else {
    /**
     * Create a class to extend (we won't use it but this will prevent a
     * ClassNotFoundException in older versions of Mautic).
     */
    class VersionSafeKickoffExecutioner {}
}

/**
 * The KickoffExecutioner class extends Mautic's KickoffExecutioner class to add
 * our additional timing and scheduling logic. 
 * 
 * We extend via a middle man class called VersionSafeKickoffExecutioner 
 * (defined above) to prevent ClassNotFoundExceptions on older versions of 
 * Mautic.
 * 
 * We basically had to override this entire class. This was necessary because
 * we needed to override private methods and change how they work significantly.
 * Going forward, it will probably be easiest to diff this class against the
 * parent class.
 * 
 * This class works with Mautic v2.14 and up.
 * 
 * @package ThirdSetMauticTimingBundle
 * @since 1.2
 */
class KickoffExecutioner extends VersionSafeKickoffExecutioner
{   
    /**
     * @var ContactLimiter
     */
    private $limiter;

    /**
     * @var Campaign
     */
    private $campaign;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var KickoffContactFinder
     */
    private $kickoffContactFinder;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var EventExecutioner
     */
    private $executioner;

    /**
     * @var EventScheduler
     */
    private $scheduler;

    /**
     * @var ProgressBar
     */
    private $progressBar;

    /**
     * @var int
     */
    private $batchCounter = 0;

    /**
     * @var ArrayCollection
     */
    private $rootEvents;

    /**
     * @var Counter
     */
    private $counter;

    /**
     * KickoffExecutioner constructor.
     *
     * @param LoggerInterface      $logger
     * @param KickoffContactFinder $kickoffContactFinder
     * @param TranslatorInterface  $translator
     * @param EventExecutioner     $executioner
     * @param EventScheduler       $scheduler
     */
    public function __construct(
        LoggerInterface $logger,
        KickoffContactFinder $kickoffContactFinder,
        TranslatorInterface $translator,
        EventExecutioner $executioner,
        EventScheduler $scheduler
    ) {
        $this->logger               = $logger;
        $this->kickoffContactFinder = $kickoffContactFinder;
        $this->translator           = $translator;
        $this->executioner          = $executioner;
        $this->scheduler            = $scheduler;
    }
    
    /**
     * @param Campaign             $campaign
     * @param ContactLimiter       $limiter
     * @param OutputInterface|null $output
     *
     * @return Counter
     *
     * @throws Dispatcher\Exception\LogNotProcessedException
     * @throws Dispatcher\Exception\LogPassedAndFailedException
     * @throws Exception\CannotProcessEventException
     * @throws NotSchedulableException
     */
    public function execute(Campaign $campaign, ContactLimiter $limiter, OutputInterface $output = null)
    {
        $this->campaign = $campaign;
        $this->limiter  = $limiter;
        $this->output   = ($output) ? $output : new NullOutput();
        $this->counter  = new Counter();

        try {
            $this->prepareForExecution();
            $this->executeOrScheduleEvent();
        } catch (NoContactsFoundException $exception) {
            $this->logger->debug('CAMPAIGN: No more contacts to process');
        } catch (NoEventsFoundException $exception) {
            $this->logger->debug('CAMPAIGN: No events to process');
        } finally {
            if ($this->progressBar) {
                $this->progressBar->finish();
                $this->output->writeln("\n");
            }
        }

        return $this->counter;
    }

    /**
     * @throws NoEventsFoundException
     */
    private function prepareForExecution()
    {
        $this->logger->debug('CAMPAIGN: Triggering kickoff events');

        $this->progressBar  = null;
        $this->batchCounter = 0;

        $this->rootEvents = $this->campaign->getRootEvents();
        $totalRootEvents  = $this->rootEvents->count();
        $this->logger->debug('CAMPAIGN: Processing the following events: '.implode(', ', $this->rootEvents->getKeys()));

        $totalContacts      = $this->kickoffContactFinder->getContactCount($this->campaign->getId(), $this->rootEvents->getKeys(), $this->limiter);
        $totalKickoffEvents = $totalRootEvents * $totalContacts;

        $this->output->writeln(
            $this->translator->trans(
                'mautic.campaign.trigger.event_count',
                [
                    '%events%' => $totalKickoffEvents,
                    '%batch%'  => $this->limiter->getBatchLimit(),
                ]
            )
        );

        if (!$totalKickoffEvents) {
            throw new NoEventsFoundException();
        }

        $this->progressBar = ProgressBarHelper::init($this->output, $totalKickoffEvents);
        $this->progressBar->start();
    }

    /**
     * @throws Dispatcher\Exception\LogNotProcessedException
     * @throws Dispatcher\Exception\LogPassedAndFailedException
     * @throws Exception\CannotProcessEventException
     * @throws NoContactsFoundException
     * @throws NotSchedulableException
     */
    private function executeOrScheduleEvent()
    {
        // Use the same timestamp across all contacts processed
        $now = new \DateTime();
        $this->counter->advanceEventCount($this->rootEvents->count());

        // Loop over contacts until the entire campaign is executed
        $contacts = $this->kickoffContactFinder->getContacts($this->campaign->getId(), $this->limiter);
        while ($contacts->count()) {
            $batchMinContactId = max($contacts->getKeys()) + 1;
            $rootEvents        = clone $this->rootEvents;

            /** @var Event $event */
            foreach ($rootEvents as $key => $event) {
                $this->progressBar->advance($contacts->count());
                $this->counter->advanceEvaluated($contacts->count());

                // Loop contacts and process them one at a time.
                foreach ($contacts as $contact) {
                    $this->scheduler->setCurrentContact($contact);
                
                    // Check if the event should be scheduled (let the schedulers do the debug logging)
                    $executionDate = $this->scheduler->getExecutionDateTime($event, $now);
                    $this->logger->debug(
                        'CAMPAIGN: Event ID# '.$event->getId().
                        ' to be executed on '.$executionDate->format('Y-m-d H:i:s').
                        ' compared to '.$now->format('Y-m-d H:i:s')
                    );

                    if ($this->scheduler->shouldSchedule($executionDate, $now)) {
                        $this->counter->advanceTotalScheduled(1);
                        $this->scheduler->schedule($event, $executionDate, new ArrayCollection(array($contact)));

                        $rootEvents->remove($key);

                        continue;
                    }
                }
            }

            if ($rootEvents->count()) {
                // Execute the events for the batch of contacts
                $this->executioner->executeEventsForContacts($rootEvents, $contacts, $this->counter);
            }

            $this->kickoffContactFinder->clear();

            if ($this->limiter->getContactId()) {
                // No use making another call
                break;
            }

            $this->logger->debug('CAMPAIGN: Fetching the next batch of kickoff contacts starting with contact ID '.$batchMinContactId);
            $this->limiter->setBatchMinContactId($batchMinContactId);

            // Get the next batch
            $contacts = $this->kickoffContactFinder->getContacts($this->campaign->getId(), $this->limiter);
        }
    }
}
