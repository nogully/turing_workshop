<?php

namespace Drupal\Tests\ultimate_cron\Kernel;

use Drupal\Tests\system\Kernel\System\CronQueueTest;
use Drupal\ultimate_cron\CronJobInterface;
use Drupal\ultimate_cron\Entity\CronJob;

/**
 * Update feeds on cron.
 *
 * @group ultimate_cron
 */
class UltimateCronQueueTest extends CronQueueTest {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('ultimate_cron');

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    module_load_install('ultimate_cron');
    ultimate_cron_install();
    $this->installSchema('ultimate_cron', [
      'ultimate_cron_log',
      'ultimate_cron_lock',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function testExceptions() {
    // Get the queue to test the normal Exception.
    $queue = $this->container->get('queue')->get('cron_queue_test_exception');

    // Enqueue an item for processing.
    $queue->createItem(array($this->randomMachineName() => $this->randomMachineName()));

    // Run cron; the worker for this queue should throw an exception and handle
    // it.
    $this->cron->run();
    $this->assertEqual(\Drupal::state()->get('cron_queue_test_exception'), 1);

    // The item should be left in the queue.
    $this->assertEqual($queue->numberOfItems(), 1, 'Failing item still in the queue after throwing an exception.');

    // Expire the queue item manually. system_cron() relies in REQUEST_TIME to
    // find queue items whose expire field needs to be reset to 0. This is a
    // Kernel test, so REQUEST_TIME won't change when cron runs.
    // @see system_cron()
    // @see \Drupal\Core\Cron::processQueues()
    $this->connection->update('queue')
      ->condition('name', 'cron_queue_test_exception')
      ->fields(['expire' => REQUEST_TIME - 1])
      ->execute();

    // Has to be manually called for Ultimate Cron.
    system_cron();

    $this->cron->run();
    $this->assertEqual(\Drupal::state()->get('cron_queue_test_exception'), 2);

    $this->assertEqual($queue->numberOfItems(), 0, 'Item was processed and removed from the queue.');
    // Get the queue to test the specific SuspendQueueException.
    $queue = $this->container->get('queue')->get('cron_queue_test_broken_queue');

    // Enqueue several item for processing.
    $queue->createItem('process');
    $queue->createItem('crash');
    $queue->createItem('ignored');

    // Run cron; the worker for this queue should process as far as the crashing
    // item.
    $this->cron->run();

    // Only one item should have been processed.
    $this->assertEqual($queue->numberOfItems(), 2, 'Failing queue stopped processing at the failing item.');

    // Check the items remaining in the queue. The item that throws the
    // exception gets released by cron, so we can claim it again to check it.
    $item = $queue->claimItem();
    $this->assertEqual($item->data, 'crash', 'Failing item remains in the queue.');
    $item = $queue->claimItem();
    $this->assertEqual($item->data, 'ignored', 'Item beyond the failing item remains in the queue.');

    // Test the requeueing functionality.
    $queue = $this->container->get('queue')->get('cron_queue_test_requeue_exception');
    $queue->createItem([]);
    $this->cron->run();

    $this->assertEqual(\Drupal::state()->get('cron_queue_test_requeue_exception'), 2);
    $this->assertFalse($queue->numberOfItems());
  }


  /**
   * Tests behavior when ultimate_cron overrides the cron processing.
   */
  public function testOverriddenProcessing() {

    $job = CronJob::load(CronJobInterface::QUEUE_ID_PREFIX . 'cron_queue_test_broken_queue');
    $this->assertNull($job);

    $this->config('ultimate_cron.settings')
      ->set('queue.enabled', TRUE)
      ->save();

    \Drupal::service('ultimate_cron.discovery')->discoverCronJobs();

    $job = CronJob::load(CronJobInterface::QUEUE_ID_PREFIX . 'cron_queue_test_broken_queue');
    $this->assertTrue($job instanceof CronJobInterface);

    /** @var \Drupal\Core\Queue\QueueInterface $queue */
    $queue = $this->container->get('queue')->get('cron_queue_test_broken_queue');

    // Enqueue several item for processing.
    $queue->createItem('process');
    $queue->createItem('process');
    $queue->createItem('process');
    $this->assertEqual(3, $queue->numberOfItems());

    // Run the job, this should process them.
    $job->run(t('Test launch'));
    $this->assertEqual(0, $queue->numberOfItems());

    // Check item delay feature.
    $this->config('ultimate_cron.settings')
      ->set('queue.delays.item_delay', 0.5)
      ->save();

    $queue->createItem('process');
    $queue->createItem('process');
    $queue->createItem('process');
    $this->assertEqual(3, $queue->numberOfItems());

    // There are 3 items, the implementation doesn't wait for the first, that
    // means this should between 1 and 1.5 seconds.
    $before = microtime(TRUE);
    $job->run(t('Test launch'));
    $after = microtime(TRUE);

    $this->assertEqual(0, $queue->numberOfItems());
    $this->assertTrue($after - $before > 1);
    $this->assertTrue($after - $after < 1.5);

    // @todo Test empty delay, causes a wait of 60 seconds with the test queue
    //   worker.
  }

}
