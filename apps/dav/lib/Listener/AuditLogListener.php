<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAV\Listener;

use OCA\DAV\Events\AddressBookCreatedEvent;
use OCA\DAV\Events\AddressBookDeletedEvent;
use OCA\DAV\Events\AddressBookShareUpdatedEvent;
use OCA\DAV\Events\CalendarCreatedEvent;
use OCA\DAV\Events\CalendarDeletedEvent;
use OCA\DAV\Events\CalendarMovedToTrashEvent;
use OCA\DAV\Events\CalendarPublishedEvent;
use OCA\DAV\Events\CalendarRestoredEvent;
use OCA\DAV\Events\CalendarShareUpdatedEvent;
use OCA\DAV\Events\CalendarUnpublishedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Override;

/**
 * Writes changes to calendars and address books to the audit log
 *
 * @template-implements IEventListener<CalendarCreatedEvent|CalendarDeletedEvent|CalendarMovedToTrashEvent|CalendarRestoredEvent|CalendarPublishedEvent|CalendarUnpublishedEvent|CalendarShareUpdatedEvent|AddressBookCreatedEvent|AddressBookDeletedEvent|AddressBookShareUpdatedEvent>
 */
class AuditLogListener implements IEventListener {
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
	) {
	}

	#[Override]
	public function handle(Event $event): void {
		if ($event instanceof CalendarCreatedEvent) {
			$this->log('Calendar "%s" of "%s" was created with URI "%s"', [
				'calendarId' => $event->getCalendarId(),
				'owner' => $event->getCalendarData()['principaluri'] ?? null,
				'uri' => $event->getCalendarData()['uri'] ?? null,
			], 'dav.calendar.created');
		} elseif ($event instanceof CalendarMovedToTrashEvent) {
			$this->logCalendar('Calendar "%s" of "%s" was moved to trash', $event->getCalendarId(), $event->getCalendarData(), 'dav.calendar.trashed');
		} elseif ($event instanceof CalendarDeletedEvent) {
			$this->log('Calendar "%s" of "%s" was deleted, removing the shares with "%s"', [
				'calendarId' => $event->getCalendarId(),
				'owner' => $event->getCalendarData()['principaluri'] ?? null,
				'sharees' => $this->implodeShareHrefs($event->getShares()),
			], 'dav.calendar.deleted');
		} elseif ($event instanceof CalendarRestoredEvent) {
			$this->logCalendar('Calendar "%s" of "%s" was restored from trash', $event->getCalendarId(), $event->getCalendarData(), 'dav.calendar.restored');
		} elseif ($event instanceof CalendarPublishedEvent) {
			// The public URI grants anonymous read access and is not logged
			$this->logCalendar('Calendar "%s" of "%s" was published', $event->getCalendarId(), $event->getCalendarData(), 'dav.calendar.published');
		} elseif ($event instanceof CalendarUnpublishedEvent) {
			$this->logCalendar('Calendar "%s" of "%s" was unpublished', $event->getCalendarId(), $event->getCalendarData(), 'dav.calendar.unpublished');
		} elseif ($event instanceof CalendarShareUpdatedEvent) {
			$this->log('Shares of calendar "%s" of "%s" were updated, added: "%s", removed: "%s"', [
				'calendarId' => $event->getCalendarId(),
				'owner' => $event->getCalendarData()['principaluri'] ?? null,
				'added' => $this->implodeShareHrefs($event->getAdded()),
				'removed' => implode(', ', $event->getRemoved()),
			], 'dav.calendar_share.updated');
		} elseif ($event instanceof AddressBookCreatedEvent) {
			$this->log('Address book "%s" of "%s" was created with URI "%s"', [
				'addressBookId' => $event->getAddressBookId(),
				'owner' => $event->getAddressBookData()['principaluri'] ?? null,
				'uri' => $event->getAddressBookData()['uri'] ?? null,
			], 'dav.addressbook.created');
		} elseif ($event instanceof AddressBookDeletedEvent) {
			$this->log('Address book "%s" of "%s" was deleted, removing the shares with "%s"', [
				'addressBookId' => $event->getAddressBookId(),
				'owner' => $event->getAddressBookData()['principaluri'] ?? null,
				'sharees' => $this->implodeShareHrefs($event->getShares()),
			], 'dav.addressbook.deleted');
		} elseif ($event instanceof AddressBookShareUpdatedEvent) {
			$this->log('Shares of address book "%s" of "%s" were updated, added: "%s", removed: "%s"', [
				'addressBookId' => $event->getAddressBookId(),
				'owner' => $event->getAddressBookData()['principaluri'] ?? null,
				'added' => $this->implodeShareHrefs($event->getAdded()),
				'removed' => implode(', ', $event->getRemoved()),
			], 'dav.addressbook_share.updated');
		}
	}

	private function logCalendar(string $message, int $calendarId, array $calendarData, string $operation): void {
		$this->log($message, [
			'calendarId' => $calendarId,
			'owner' => $calendarData['principaluri'] ?? null,
		], $operation);
	}

	/**
	 * @param array<array{href: string, ...}> $shares
	 */
	private function implodeShareHrefs(array $shares): string {
		return implode(', ', array_map(static fn (array $share): string => $share['href'], $shares));
	}

	/**
	 * @param array<string, scalar|null> $parameters
	 */
	private function log(string $message, array $parameters, string $operation): void {
		$this->dispatcher->dispatchTyped(new CriticalActionPerformedEvent($message, $parameters, false, $operation));
	}
}
