<?php

namespace Drupal\workspace\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\multiversion\Entity\WorkspaceInterface;
use Drupal\workbench_moderation\Event\WorkbenchModerationEvents;
use Drupal\workbench_moderation\Event\WorkbenchModerationTransitionEvent;
use Drupal\workspace\ReplicatorManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscriber for workbench transitions.
 */
class WorkbenchModerationSubscriber implements EventSubscriberInterface {

  /**
   * The entity type manager to use for checking moderation information.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The replicator manager to trigger replication on.
   *
   * @var \Drupal\workspace\ReplicatorManager
   */
  protected $replicatorManager;

  /**
   * Inject dependencies.
   *
   * @param EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager to use for checking moderation information.
   * @param ReplicatorManager $replicator_manager
   *   The replicator manager to trigger replication on.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ReplicatorManager $replicator_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->replicatorManager = $replicator_manager;
  }

  /**
   * Listener for workbench moderation event transitions.
   *
   * @param \Drupal\workbench_moderation\Event\WorkbenchModerationTransitionEvent $event
   *   The transition event that just fired.
   */
  public function onTransition(WorkbenchModerationTransitionEvent $event) {
    /* @var WorkspaceInterface $entity */
    $entity = $event->getEntity();

    if ($entity->getEntityTypeId() == 'workspace' && $this->wasDefaultRevision($event)) {

      // If there is no upstream to replicate to, abort.
      if (!$entity->get('upstream')->entity) {
        drupal_set_message(t('The :source workspace does not have an upstream to replicate to!', [
          ':source' => $entity->label(),
        ]), 'error');

        // @todo Should we revert the workspace to its previous state?

        return;
      }

      $log = $this->mergeWorkspaceToParent($entity);

      // Display a message to the user on what happened.
      if ($log->get('ok')->value === TRUE) {
        drupal_set_message(t('Changes in :source were replicated to :target.', [
          ':source' => $entity->label(),
          ':target' => $entity->get('upstream')->entity->label(),
        ]), 'status');
      }
      else {
        // @todo Where do we get more info about why it failed? Should we add a
        // description field to the replication log?
        drupal_set_message(t('Publishing the :source workspace failed!', [
          ':source' => $entity->label(),
          ':target' => $entity->get('upstream')->entity->label(),
        ]), 'error');

        // You cannot revert an entity to a previous moderation state inside a
        // state transition subscriber. As such, use a runtime state like a static
        // variable to pass the revert state back to the caller of this
        // transition, such as the workspace edit form.
        // @see \Drupal\workspace\Entity\Form\WorkspaceForm
        drupal_static('workspace_revert_publish_workspace', $event->getStateBefore());
      }
    }
  }

  /**
   * Determines if the transition is to a "default revision" state.
   *
   * @param \Drupal\workbench_moderation\Event\WorkbenchModerationTransitionEvent $event
   *   The transition event.
   *
   * @return bool
   *   TRUE if the event is moving an entity to a default-revision state.
   */
  protected function wasDefaultRevision(WorkbenchModerationTransitionEvent $event) {
    /** @var Drupal\workbench_moderation\Entity\ModerationState $post_state */
    $post_state = $this->entityTypeManager->getStorage('moderation_state')->load($event->getStateAfter());

    return $post_state->isPublishedState();
  }

  /**
   * Merges a workspace to its parent workspace, if any.
   *
   * @param \Drupal\multiversion\Entity\WorkspaceInterface $workspace
   *   The workspace entity to merge.
   *
   * @return \Drupal\replication\Entity\ReplicationLog
   *   The replication log entry.
   */
  protected function mergeWorkspaceToParent(WorkspaceInterface $workspace) {
    /* @var \Drupal\workspace\WorkspacePointerInterface $parent_workspace */
    $parent_workspace_pointer = $workspace->get('upstream')->entity;

    /* @var \Drupal\workspace\WorkspacePointerInterface $source_pointer */
    $source_pointer = $this->getPointerToWorkspace($workspace);

    // Derive a replication task from the Workspace we are acting on.
    $task = $this->replicatorManager->getTask($workspace, 'push_replication_settings');

    return $this->replicatorManager->replicate($source_pointer, $parent_workspace_pointer, $task);
  }

  /**
   * Returns a pointer to the specified workspace.
   *
   * In most cases this pointer will be unique, but that is not guaranteed
   * by the schema. If there are multiple pointers, which one is returned is
   * undefined.
   *
   * @todo Move this to somewhere more logical and globally accessible.
   *
   * @param \Drupal\multiversion\Entity\WorkspaceInterface $workspace
   *   The workspace for which we want a pointer.
   *
   * @return \Drupal\workspace\WorkspacePointerInterface
   *   The pointer to the provided workspace.
   */
  protected function getPointerToWorkspace(WorkspaceInterface $workspace) {
    $pointers = $this->entityTypeManager
      ->getStorage('workspace_pointer')
      ->loadByProperties(['workspace_pointer' => $workspace->id()]);
    $pointer = reset($pointers);
    return $pointer;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events = [];
    if (class_exists(WorkbenchModerationEvents::class)) {
      $events[WorkbenchModerationEvents::STATE_TRANSITION][] = ['onTransition'];
    }
    return $events;
  }

}
