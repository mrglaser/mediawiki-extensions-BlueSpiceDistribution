<?php

// @todo Fill in
class EchoNotifier {
	/**
	 * Record an EchoNotification for an EchoEvent
	 * Currently used for web-based notifications.
	 *
	 * @param $user User to notify.
	 * @param $event EchoEvent to notify about.
	 */
	public static function notifyWithNotification( $user, $event ) {
		global $wgEchoConfig, $wgEchoNotifications;

		// Only create the notification if the user wants to recieve that type
		// of notification and they are eligible to recieve it. See bug 47664.
		$userWebNotifications = EchoNotificationController::getUserEnabledEvents( $user, 'web' );
		if ( !in_array( $event->getType(), $userWebNotifications ) ) {
			return;
		}

		EchoNotification::create( array( 'user' => $user, 'event' => $event ) );

		MWEchoEventLogging::logSchemaEcho( $user, $event, 'web' );
	}

	/**
	 * Send a Notification to a user by email
	 *
	 * @param $user User to notify.
	 * @param $event EchoEvent to notify about.
	 * @return bool
	 */
	public static function notifyWithEmail( $user, $event ) {
		// No valid email address or email notification
		if ( !$user->isEmailConfirmed() || $user->getOption( 'echo-email-frequency' ) < 0 ) {
			return false;
		}

		// Final check on whether to send email for this user & event
		if ( !wfRunHooks( 'EchoAbortEmailNotification', array( $user, $event ) ) ) {
			return false;
		}

		// See if the user wants to receive emails for this category or the user is eligible to receive this email
		if ( in_array( $event->getType(), EchoNotificationController::getUserEnabledEvents( $user, 'email' ) ) ) {
			global $wgEchoEnableEmailBatch, $wgEchoNotifications, $wgNotificationSender, $wgNotificationSenderName, $wgNotificationReplyName, $wgEchoBundleEmailInterval;

			$priority = EchoNotificationController::getNotificationPriority( $event->getType() );

			$bundleString = $bundleHash = '';

			// We should have bundling for email digest as long as either web or email bundling is on, for example, talk page
			// email bundling is off, but if a user decides to receive email digest, we should bundle those messages
			if ( !empty( $wgEchoNotifications[$event->getType()]['bundle']['web'] ) || !empty( $wgEchoNotifications[$event->getType()]['bundle']['email'] ) ) {
				wfRunHooks( 'EchoGetBundleRules', array( $event, &$bundleString ) );
			}
			if ( $bundleString ) {
				$bundleHash = md5( $bundleString );
			}

			MWEchoEventLogging::logSchemaEcho( $user, $event, 'email' );

			// email digest notification ( weekly or daily )
			if ( $wgEchoEnableEmailBatch && $user->getOption( 'echo-email-frequency' ) > 0 ) {
				// always create a unique event hash for those events don't support bundling
				// this is mainly for group by
				if ( !$bundleHash ) {
					$bundleHash = md5( $event->getType() . '-' . $event->getId() );
				}
				MWEchoEmailBatch::addToQueue( $user->getId(), $event->getId(), $priority, $bundleHash );
				return true;
			}

			$addedToQueue = false;

			// only send bundle email if email bundling is on
			if ( $wgEchoBundleEmailInterval && $bundleHash && !empty( $wgEchoNotifications[$event->getType()]['bundle']['email'] ) ) {
				$bundler = MWEchoEmailBundler::newFromUserHash( $user, $bundleHash );
				if ( $bundler ) {
					$addedToQueue = $bundler->addToEmailBatch( $event->getId(), $priority );
				}
			}

			// send single notification if the email wasn't added to queue for bundling
			if ( !$addedToQueue ) {
				// instant email notification
				$toAddress = new MailAddress( $user );
				$fromAddress = new MailAddress( $wgNotificationSender, $wgNotificationSenderName );
				$replyAddress = new MailAddress( $wgNotificationSender, $wgNotificationReplyName );
				// Since we are sending a single email, should set the bundle hash to null
				// if it is set with a value from somewhere else
				$event->setBundleHash( null );
				$email = EchoNotificationController::formatNotification( $event, $user, 'email', 'email' );
				$subject = $email['subject'];
				$body = $email['body'];

				UserMailer::send( $toAddress, $fromAddress, $subject, $body, $replyAddress );
				MWEchoEventLogging::logSchemaEchoMail( $user, 'single' );
			}
		}

		return true;
	}
}
