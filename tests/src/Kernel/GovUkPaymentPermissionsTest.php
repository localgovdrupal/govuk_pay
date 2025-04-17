<?php

namespace Drupal\Tests\govuk_pay\Kernel;

use Drupal\govuk_pay\Entity\GovUkPayment;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Tests the permission handling for GOV.UK Payment entities.
 *
 * @group govuk_pay
 */
class GovUkPaymentPermissionsTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'govuk_pay',
  ];

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The access control handler.
   *
   * @var \Drupal\Core\Entity\EntityAccessControlHandlerInterface
   */
  protected $accessHandler;

  /**
   * The anonymous user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $anonymousUser;

  /**
   * A regular user with no special permissions.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $regularUser;

  /**
   * A user with 'view any govukpayment entity' permission.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $viewAnyUser;

  /**
   * A user with 'administer govukpayment entity' permission.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * A payment owned by regularUser.
   *
   * @var \Drupal\govuk_pay\Entity\GovUkPayment
   */
  protected $ownedPayment;

  /**
   * A payment not owned by regularUser.
   *
   * @var \Drupal\govuk_pay\Entity\GovUkPayment
   */
  protected $otherPayment;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('govukpayment');
    $this->installSchema('system', ['sequences']);

    $this->entityTypeManager = $this->container->get('entity_type.manager');
    $this->accessHandler = $this->entityTypeManager->getAccessControlHandler('govukpayment');

    // Create the anonymous user.
    $this->anonymousUser = $this->createUser([], '', FALSE, ['uid' => 0]);

    // Create a regular user with no special permissions.
    $this->regularUser = $this->createUser([], 'regular_user', FALSE, ['uid' => 25]);
    // Ensure the regular user is not an admin.
    $this->regularUser->set('status', 1);
    $this->regularUser->save();

    // Create a user with 'view any govukpayment entity' permission.
    $this->viewAnyUser = $this->createUser(['view any govukpayment entity'], 'view_any_user', FALSE, ['uid' => 26]);
    // Ensure the view any user is not an admin.
    $this->viewAnyUser->set('status', 1);
    $this->viewAnyUser->save();

    // Create a user with 'administer govukpayment entity' permission.
    $this->adminUser = $this->createUser(['administer govukpayment entity'], 'admin_user', FALSE, ['uid' => 27]);
    // Ensure the admin user is not an admin (they just have the permission).
    $this->adminUser->set('status', 1);
    $this->adminUser->save();

    // Create another user to be the owner of otherPayment.
    $otherUser = $this->createUser([], 'other_user', FALSE, ['uid' => 28]);

    // Create a payment owned by regularUser.
    $this->ownedPayment = GovUkPayment::create([
      'payment_id' => 'payment-123',
      'status' => 'created',
      'amount' => 1000,
      'payment_for' => 'Test payment 1',
      'payment_reference' => 'REF-123',
      'uid' => $this->regularUser->id(),
    ]);
    $this->ownedPayment->save();

    // Create a payment not owned by regularUser.
    $this->otherPayment = GovUkPayment::create([
      'payment_id' => 'payment-456',
      'status' => 'created',
      'amount' => 2000,
      'payment_for' => 'Test payment 2',
      'payment_reference' => 'REF-456',
      'uid' => $otherUser->id(),
    ]);
    $this->otherPayment->save();
  }

  /**
   * Tests that anonymous users cannot view payment entities.
   */
  public function testAnonymousUserAccess() {
    // Test 1.1: Anonymous user can't view the overview Drupal View.
    $this->assertFalse(
      $this->anonymousUser->hasPermission('administer govukpayment entity'),
      'Anonymous user should not have admin permission for GOV.UK Payment entities.'
    );

    // Test 1.2: Anonymous user can't view an individual payment entity.
    $this->assertFalse(
      $this->accessHandler->access($this->ownedPayment, 'view', $this->anonymousUser),
      'Anonymous user should not have access to view a payment entity.'
    );
  }

  /**
   * Tests that regular users can only view their own payment entities.
   */
  public function testRegularUserAccess() {
    // Test 2.1: Regular user can view their own payment entity.
    $this->assertTrue(
      $this->accessHandler->access($this->ownedPayment, 'view', $this->regularUser),
      'Regular user should have access to view their own payment entity.'
    );

    // Test 2.2: Regular user cannot view payment entities they don't own.
    // We need to override the access control handler behavior for this test.
    $this->assertAccessResult(
      $this->accessHandler,
      $this->otherPayment,
      'view',
      $this->regularUser,
      FALSE,
      'Regular user should not have access to view payment entities they do not own.'
    );

    // Test 2.3: Regular user cannot view the overview Drupal View.
    $this->assertFalse(
      $this->regularUser->hasPermission('administer govukpayment entity'),
      'Regular user should not have admin permission for GOV.UK Payment entities.'
    );
  }

  /**
   * Tests that users with 'view any' permission can view any payment entity.
   */
  public function testViewAnyUserAccess() {
    // Test 3.1: User with 'view any' permission can view any payment entity.
    $this->assertTrue(
      $this->accessHandler->access($this->ownedPayment, 'view', $this->viewAnyUser),
      'User with view any permission should have access to view any payment entity.'
    );

    $this->assertTrue(
      $this->accessHandler->access($this->otherPayment, 'view', $this->viewAnyUser),
      'User with view any permission should have access to view any payment entity.'
    );

    // Test 3.2: User with 'view any' permission cannot view the overview
    // Drupal View.
    $this->assertFalse(
      $this->viewAnyUser->hasPermission('administer govukpayment entity'),
      'User with view any permission should not have admin permission for GOV.UK Payment entities.'
    );
  }

  /**
   * Tests that admin users can view the overview Drupal View.
   */
  public function testAdminUserAccess() {
    // Admin user can view any payment entity.
    $this->assertTrue(
      $this->accessHandler->access($this->ownedPayment, 'view', $this->adminUser),
      'Admin user should have access to view any payment entity.'
    );

    $this->assertTrue(
      $this->accessHandler->access($this->otherPayment, 'view', $this->adminUser),
      'Admin user should have access to view any payment entity.'
    );

    // Admin user can view the overview Drupal View.
    $this->assertTrue(
      $this->adminUser->hasPermission('administer govukpayment entity'),
      'Admin user should have admin permission for GOV.UK Payment entities.'
    );
  }

  /**
   * Helper method to assert an access result.
   *
   * @param \Drupal\Core\Entity\EntityAccessControlHandlerInterface $access_handler
   *   The access handler.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check access for.
   * @param string $operation
   *   The operation to check.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to check access for.
   * @param bool $expected
   *   The expected result.
   * @param string $message
   *   The assertion message.
   */
  protected function assertAccessResult($access_handler, $entity, $operation, AccountInterface $account, $expected, $message) {
    // Create a mock access result based on the expected outcome.
    $mock_result = $expected ? AccessResult::allowed() : AccessResult::forbidden();
    // Get the actual result.
    $actual_result = $access_handler->access($entity, $operation, $account, TRUE);
    // Assert that the actual result matches the expected result.
    $this->assertEquals(
      $mock_result->isAllowed(),
      $actual_result->isAllowed(),
      $message
    );
  }

}
