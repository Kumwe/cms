<?php
/**
 * @package    Kumwe CMS
 *
 * @created    9th April 2022
 * @author     Llewellyn van der Merwe <https://git.vdm.dev/Llewellyn>
 * @git        Kumwe CMS <https://git.vdm.dev/Kumwe/cms>
 * @license    GNU General Public License version 2; see LICENSE.txt
 */

namespace Kumwe\CMS\Application;

use Joomla\Filter\InputFilter as InputFilterAlias;
use Kumwe\CMS\Filter\InputFilter;

/**
 * Trait for application classes which are identity (user) aware
 *
 * @since  1.0.0
 */
trait SessionMessageAwareTrait
{
	/**
	 * Enqueue a system message.
	 *
	 * @param   string  $msg   The message to enqueue.
	 * @param   string  $type  The message type. Default is message.
	 *
	 * @return  void
	 *
	 * @since   1.0.0
	 */
	public function enqueueMessage(string $msg, string $type = self::MSG_INFO)
	{
		// Don't add empty messages.
		if ($msg === null || trim($msg) === '')
		{
			return;
		}

		$inputFilter = InputFilter::getInstance(
			[],
			[],
			InputFilterAlias::ONLY_BLOCK_DEFINED_TAGS,
			InputFilterAlias::ONLY_BLOCK_DEFINED_ATTRIBUTES
		);

		// Build the message array and apply the HTML InputFilter with the default blacklist to the message
		$message = array(
			'message' => $inputFilter->clean($msg, 'html'),
			'type'    => $inputFilter->clean(strtolower($type), 'cmd'),
		);

		// For empty queue, if messages exists in the session, enqueue them first.
		$messages = $this->getMessageQueue();

		if (!\in_array($message, $messages))
		{
			// Enqueue the message.
			$messages[] = $message;

			// update the session
			$this->getSession()->set('application.queue', $messages);
		}
	}

	/**
	 * Get the system message queue.
	 *
	 * @param   boolean  $clear  Clear the messages currently attached to the application object
	 *
	 * @return  array  The system message queue.
	 *
	 * @since   1.0.0
	 */
	public function getMessageQueue(bool $clear = false): array
	{
		// Get messages from Session
		$sessionQueue = $this->getSession()->get('application.queue', []);

		if ($clear)
		{
			$this->getSession()->set('application.queue', []);
		}

		return $sessionQueue;
	}
}
