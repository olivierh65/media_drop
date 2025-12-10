<?php

namespace Drupal\media_drop\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for sending email notifications.
 */
class NotificationService {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;
  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Constructs a NotificationService.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MailManagerInterface $mail_manager, LanguageManagerInterface $language_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->mailManager = $mail_manager;
    $this->languageManager = $language_manager;
  }

  /**
   * Send notification about uploaded media batch.
   *
   * @param object $album
   *   The album object.
   * @param array $uploaded_files
   *   Array of uploaded files with keys: filename, user_name, media.
   */
  public function notifyUploadBatch($user_name, $album, array $uploaded_files) {
    // Check if notifications are enabled for this album.
    if (empty($album->enable_notifications)) {
      return;
    }

    if (empty($uploaded_files)) {
      return;
    }

    $recipients = $this->getNotificationRecipients($album);

    if (empty($recipients)) {
      return;
    }

    // Prepare email content with all files.
    $params = [
      'album' => $album,
      'uploaded_files' => $uploaded_files,
      'file_count' => count($uploaded_files),
      'user_name' => $user_name,
    ];

    // Send a single email to all recipients.
    foreach ($recipients as $email) {
      $this->mailManager->mail(
        'media_drop',
        'upload_notification',
        $email,
        $this->languageManager->getDefaultLanguage()->getId(),
        $params
      );
    }
  }

  /**
   * Send notification about a single uploaded media (legacy method).
   *
   * @deprecated Use notifyUploadBatch() instead.
   */
  public function notifyUpload($album, $filename, $user_name, $media = NULL) {
    $this->notifyUploadBatch($album, [[
      'filename' => $filename,
      'user_name' => $user_name,
      'media' => $media,
    ],
    ]);
  }

  /**
   * Get list of notification recipients from album settings.
   */
  protected function getNotificationRecipients($album) {
    $recipients = [];

    // Get users with specified roles.
    if (!empty($album->notification_roles)) {
      $role_ids = array_filter(explode(',', $album->notification_roles));

      if (!empty($role_ids)) {
        // Query users with these roles.
        $user_storage = $this->entityTypeManager->getStorage('user');
        $query = $user_storage->getQuery()
          ->accessCheck(FALSE)
          // Only active users.
          ->condition('status', 1);

        // Add OR condition for each role.
        $or_group = $query->orConditionGroup();
        foreach ($role_ids as $role_id) {
          $or_group->condition('roles', $role_id);
        }
        $query->condition($or_group);

        $user_ids = $query->execute();

        if (!empty($user_ids)) {
          $users = $user_storage->loadMultiple($user_ids);
          foreach ($users as $user) {
            if (!empty($user->getEmail())) {
              $recipients[] = $user->getEmail();
            }
          }
        }
      }
    }

    // Add additional email if configured.
    if (!empty($album->notification_email)) {
      $recipients[] = $album->notification_email;
    }

    // Remove duplicates.
    $recipients = array_unique($recipients);

    return $recipients;
  }

}
