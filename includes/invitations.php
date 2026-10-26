<?php
/**
 * CASMS — Invitations from outside groups
 *
 * An outside group sends an invitation through the public form (no account).
 * The office decides; an approved ("Posted") invitation shows on the calendar
 * and is shared with the unit's coordinators. This file holds who may see
 * one, the form's rate limit, and the two messages a decision sends.
 */

declare(strict_types=1);

/**
 * May the signed-in user see invitations at all?
 *
 * An explicit list, not "anyone but a student": a role added later (Guest)
 * must get nothing until someone decides it should.
 */
function can_view_invitations(): bool
{
    return is_office_staff() || in_array(current_role(), ['coordinator', 'guest'], true);
}

/**
 * One invitation if the current user may see it, otherwise null.
 * The office sees every row; a coordinator sees only what was posted or
 * later cancelled, never what is pending or rejected.
 *
 * @return array<string, mixed>|null
 */
function fetch_visible_invitation(int $id): ?array
{
    if (!can_view_invitations()) {
        return null;
    }

    if (is_office_staff()) {
        return fetch_one('SELECT * FROM invitations WHERE invitation_id = ?', [$id]);
    }
    if (current_role() === 'guest') {
        return fetch_one('SELECT * FROM invitations WHERE invitation_id = ? AND submitted_by = ?', [$id, current_user_id()]);
    }

    return fetch_one(
        "SELECT * FROM invitations
          WHERE invitation_id = ? AND status IN ('approved', 'cancelled')",
        [$id]
    );
}

/**
 * Has this address or this email already sent too many in the last hour?
 *
 * shortcut: REMOTE_ADDR is the proxy's address behind a reverse proxy; read
 * the proxy's forwarded-for header there once IT publishes the server.
 */
function invitation_submissions_limited(string $ipAddress, string $email): bool
{
    foreach (['ip_address' => $ipAddress, 'email' => $email] as $column => $value) {
        // $column comes from the literal array above, never from input.
        $count = (int) fetch_value(
            "SELECT COUNT(*) FROM invitations
              WHERE $column = ? AND created_at >= (NOW() - INTERVAL 60 MINUTE)",
            [$value]
        );
        if ($count >= INVITATION_MAX_PER_HOUR) {
            return true;
        }
    }
    return false;
}

/** "Oct 24, 2026 9:00 AM to 5:00 PM", or "... to Oct 26, 2026 5:00 PM" over several days. */
function invitation_when(array $invitation): string
{
    $start = (string) $invitation['start_at'];
    $end   = (string) $invitation['end_at'];
    $when  = format_datetime($start);

    if ($end <= $start) {
        return $when;
    }

    $sameDay = substr($start, 0, 10) === substr($end, 0, 10);
    return $when . ' to ' . ($sameDay ? date('g:i A', strtotime($end)) : format_datetime($end));
}

/**
 * Email the sender the outcome. The invitation array carries the decided
 * status and, for a rejection, the remarks.
 *
 * @return string 'sent' | 'logged' | 'failed'
 */
function invitation_send_decision(array $invitation): string
{
    if ($invitation['submitted_by'] !== null) {
        $approved = $invitation['status'] === 'approved';
        $body = 'Good day, ' . $invitation['contact_name'] . ".\n\nYour program \"" . $invitation['title']
            . '" has been ' . ($approved ? 'approved and added to your calendar.' : 'rejected.')
            . "\nWhen: " . invitation_when($invitation) . "\nPlace: " . $invitation['place']
            . ($approved ? '' : "\nReason: " . $invitation['remarks'])
            . "\n\n" . absolute_url(url('invitations/view.php?id=' . (int) $invitation['invitation_id']));
        if (!send_mail($invitation['email'], 'Program ' . ($approved ? 'approved: ' : 'rejected: ') . $invitation['title'], $body)) {
            return 'failed';
        }
        return email_delivers() ? 'sent' : 'logged';
    }
    $subject = 'Your invitation to ' . university_name() . ': ' . $invitation['title'];

    $lines = [
        'Good day, ' . $invitation['contact_name'] . '.',
        '',
        'Thank you for inviting ' . university_name() . ' to "' . $invitation['title'] . '" ('
            . invitation_when($invitation) . ', ' . $invitation['place'] . ').',
        '',
    ];

    if ($invitation['status'] === 'approved') {
        $lines[] = 'The ' . office_name() . " has received your invitation and shared it with the unit's coordinators. "
            . 'If the university sends participants, the office will get in touch with you at this email address.';
    } else {
        $lines[] = 'The ' . office_name() . ' has reviewed your invitation and is not able to take it up.';
        $lines[] = '';
        $lines[] = 'Reason: ' . $invitation['remarks'];
    }

    array_push(
        $lines,
        '',
        office_name(),
        university_name(),
        '',
        'This is an automated message. Please do not reply.'
    );

    if (!send_mail((string) $invitation['email'], $subject, implode(PHP_EOL, $lines))) {
        return 'failed';
    }
    return email_delivers() ? 'sent' : 'logged';
}

/**
 * Tell admins and coordinators about a change to an invitation. Staff are
 * left out on purpose: they made the decision. Stored as 'system' because the
 * bell shows essential types only.
 */
function invitation_notify(array $invitation, string $title, string $message): void
{
    $ids = array_map('intval', array_column(fetch_all(
        "SELECT u.user_id
           FROM users u
           JOIN roles r ON r.role_id = u.role_id
          WHERE u.status = 'active' AND r.name IN ('admin', 'coordinator')"
    ), 'user_id'));

    // Column limits: notifications.title 200, message 500.
    notify_many(
        $ids,
        'system',
        mb_strimwidth($title, 0, 200, '…'),
        mb_strimwidth($message, 0, 500, '…'),
        url('invitations/view.php?id=' . (int) $invitation['invitation_id'])
    );
}

function invitation_notify_approved(array $invitation): void
{
    invitation_notify(
        $invitation,
        'New invitation: ' . $invitation['title'],
        $invitation['organization'] . ' invites BukSU to ' . $invitation['title'] . ', '
            . invitation_when($invitation) . ', ' . $invitation['place'] . '. It is on the calendar.'
    );
}

function invitation_notify_cancelled(array $invitation): void
{
    invitation_notify(
        $invitation,
        'Invitation cancelled: ' . $invitation['title'],
        $invitation['organization'] . "'s invitation to " . $invitation['title'] . ', '
            . invitation_when($invitation) . ', is cancelled. It stays on the calendar, marked cancelled.'
    );
}
