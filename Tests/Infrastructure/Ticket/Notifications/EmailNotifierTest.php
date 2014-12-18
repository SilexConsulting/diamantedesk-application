<?php
/*
 * Copyright (c) 2014 Eltrino LLC (http://eltrino.com)
 *
 * Licensed under the Open Software License (OSL 3.0).
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://opensource.org/licenses/osl-3.0.php
 *
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@eltrino.com so we can send you a copy immediately.
 */
namespace Diamante\DeskBundle\Tests\Infrastructure\Ticket\Notifications;

use Diamante\DeskBundle\Infrastructure\Ticket\Notifications\EmailNotifier;
use Diamante\DeskBundle\Model\Branch\Branch;
use Diamante\DeskBundle\Model\Ticket\Notifications\Email\TemplateResolver;
use Diamante\DeskBundle\Model\Ticket\Notifications\Notification;
use Diamante\DeskBundle\Model\Ticket\Priority;
use Diamante\DeskBundle\Model\Ticket\Source;
use Diamante\DeskBundle\Model\Ticket\Status;
use Diamante\DeskBundle\Model\Ticket\Ticket;
use Diamante\DeskBundle\Model\Ticket\TicketSequenceNumber;
use Diamante\DeskBundle\Model\Ticket\UniqueId;
use Eltrino\PHPUnit\MockAnnotations\MockAnnotations;
use Oro\Bundle\UserBundle\Entity\User;
use Diamante\DeskBundle\Model\User\User as DiamanteUser;

class EmailNotifierTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var \Twig_Environment
     * @Mock \Twig_Environment
     */
    private $twig;

    /**
     * @var \Swift_Mailer
     * @Mock \Swift_Mailer
     */
    private $mailer;

    /**
     * @var \Diamante\DeskBundle\Model\Ticket\Notifications\Email\TemplateResolver
     * @Mock \Diamante\DeskBundle\Model\Ticket\Notifications\Email\TemplateResolver
     */
    private $templateResolver;

    /**
     * @var \Diamante\DeskBundle\Model\Ticket\TicketRepository
     * @Mock \Diamante\DeskBundle\Model\Ticket\TicketRepository
     */
    private $ticketRepository;

    /**
     * @var \Diamante\DeskBundle\Model\Shared\UserService
     * @Mock \Diamante\DeskBundle\Model\Shared\UserService
     */
    private $userService;

    /**
     * @var \Oro\Bundle\LocaleBundle\Formatter\NameFormatter
     * @Mock \Oro\Bundle\LocaleBundle\Formatter\NameFormatter
     */
    private $nameFormatter;

    /**
     * @var string
     */
    private $senderEmail = 'sender@host.com';

    /**
     * @var string
     */
    private $senderHost = 'host.com';

    /**
     * @var \Diamante\ApiBundle\Entity\ApiUser
     * @Mock Diamante\ApiBundle\Entity\ApiUser
     */
    private $apiUser;

    protected function setUp()
    {
        MockAnnotations::init($this);
    }

    public function testNotify()
    {
        $ticketUniqueId = UniqueId::generate();
        $reporter = new DiamanteUser(1, DiamanteUser::TYPE_DIAMANTE);
        $assignee = new User();
        $assignee->setId(2);
        $assignee->setEmail('assignee@host.com');
        $author = new User();
        $author->setId(3);
        $branch = new Branch('KEY', 'Name', 'Description');
        $ticket = new Ticket(
            $ticketUniqueId, new TicketSequenceNumber(1), 'Subject', 'Description', $branch, $reporter, $assignee,
            new Source(Source::WEB), new Priority(Priority::PRIORITY_MEDIUM), new Status(Status::NEW_ONE)
        );
        $notification = new Notification(
            (string) $ticketUniqueId, $author, 'Header', 'Subject', new \ArrayIterator(array('key' => 'value')), array('file.ext')
        );

        $message = new \Swift_Message();

        $this->nameFormatter->expects($this->once())->method('format')->with($author)->will($this->returnValue('First Last'));
        $this->mailer->expects($this->once())->method('createMessage')->will($this->returnValue($message));
        $this->ticketRepository->expects($this->once())->method('getByUniqueId')->with($ticketUniqueId)
            ->will($this->returnValue($ticket));

        $this->apiUser
            ->expects($this->atLeastOnce())
            ->method('getEmail')
            ->will($this->returnValue('reporter@host.com'));

        $this->userService->expects($this->any())->method('getByUser')->will(
            $this->returnValueMap(array(
                array(new DiamanteUser($author->getId(), DiamanteUser::TYPE_ORO), $author),
                array($reporter, $this->apiUser),
                array(new DiamanteUser($assignee->getId(), DiamanteUser::TYPE_ORO), $assignee)
            ))
        );

        $this->templateResolver->expects($this->any())->method('resolve')->will(
            $this->returnValueMap(array(
                array($notification, TemplateResolver::TYPE_TXT, 'txt.template.html'),
                array($notification, TemplateResolver::TYPE_HTML, 'html.template.html')
            ))
        );

        $optionsConstraint = $this->logicalAnd(
            $this->arrayHasKey('changes'), $this->arrayHasKey('attachments'), $this->arrayHasKey('user'), $this->arrayHasKey('header'),
            $this->contains($notification->getChangeList()), $this->contains($notification->getAttachments()),
            $this->contains('First Last'), $this->contains($notification->getHeaderText()));

        $this->twig->expects($this->at(0))->method('render')->with('txt.template.html', $optionsConstraint)
            ->will($this->returnValue('Rendered TXT template'));
        $this->twig->expects($this->at(1))->method('render')->with('html.template.html', $optionsConstraint)
            ->will($this->returnValue('Rendered HTML template'));

        $this->mailer->expects($this->once())->method('send')->with(
            $this->logicalAnd(
                $this->isInstanceOf('\Swift_Message'),
                $this->callback(function(\Swift_Message $other) use($notification){
                    $to = $other->getTo();
                    return false !== strpos($other->getSubject(), $notification->getSubject())
                        && false !== strpos($other->getSubject(), 'KEY-1')
                        && false !== strpos($other->getBody(), 'Rendered TXT template')
                        && array_key_exists('reporter@host.com', $to) && array_key_exists('assignee@host.com', $to);
                })
            )
        );

        $notifier = new EmailNotifier(
            $this->twig, $this->mailer, $this->templateResolver, $this->ticketRepository, $this->userService,
            $this->nameFormatter, $this->senderEmail, $this->senderHost
        );

        $notifier->notify($notification);
    }
}
