<?php
/**
 * Email mailer
 */

namespace Minds\Core\Email;

use Minds\Core;
use Minds\Core\Di\Di;
use Minds\Core\Log\Logger;
use Minds\Core\Queue\Client as Queue;
use PHPMailer;
use Minds\Core\Config\Config;
use Minds\Core\MultiTenant\Configs\Manager as TenantConfigsManager;

class Mailer
{
    private $mailer;
    private $queue;
    private $stats;

    /** @var SpamFilter */
    private $filter;

    public function __construct(
        $mailer = null,
        $queue = null,
        $filter = null,
        private ?Logger $logger = null,
        private ?Config $config = null,
        private ?TenantConfigsManager $tenantConfigsManager = null
    ) {
        $this->mailer = $mailer ?: new PHPMailer();
        if (isset(Core\Config::_()->email['smtp'])) {
            $this->setup();
        }
        $this->stats = [
            'sent' => 0,
            'failed' => 0
        ];
        $this->queue = $queue ?: Queue::build();
        $this->filter = $filter ?: Di::_()->get('Email\SpamFilter');
        $this->logger ??= Di::_()->get('Logger');
        $this->config ??= Di::_()->get(Config::class);
        $this->tenantConfigsManager??= Di::_()->get(TenantConfigsManager::class);
    }

    private function setup()
    {
        $this->mailer->isSMTP();
        //$this->mailer->SMTPKeepAlive = true;
        $this->mailer->Host = Core\Config::_()->email['smtp']['host'];
        $this->mailer->Auth = Core\Config::_()->email['smtp']['auth'];
        $this->mailer->SMTPAuth = true;
        $this->mailer->Username = Core\Config::_()->email['smtp']['username'];
        $this->mailer->Password = Core\Config::_()->email['smtp']['password'];
        $this->mailer->SMTPSecure = Core\Config::_()->email['smtp']['smtp_secure'] ?? "ssl";
        $this->mailer->Port = Core\Config::_()->email['smtp']['port'];
    }

    public function queue($message, $priority = false)
    {
        $queueName = $priority ? 'PriorityEmail' : 'Email';
        try {
            $this->queue->setQueue($queueName)
                ->send([
                    "message" => serialize($message)
                ]);
        } catch (\Exception $e) {
            error_log(print_r($e, true));
        }
    }

    /**
     * Send an email
     * @param Message $message
     * @return $this
     */
    public function send($message)
    {
        $this->mailer->ClearAllRecipients();
        $this->mailer->ClearAttachments();

        $isTenant = (bool)$this->config->get('tenant_id');
        $replyTo = $message->getReplyTo();

        if (!empty($replyTo) && !$isTenant) {
            // Set custom reply-to in the email
            $this->mailer->ClearReplyTos();
            $this->mailer->addReplyTo($replyTo['email'], $replyTo['name'] ?? 'Minds');
        } elseif ($isTenant) {
            $configs = $this->tenantConfigsManager->getConfigs() ?? null;
            $replyEmail = $configs?->replyEmail ?: 'no-reply@minds.com';

            $siteName = $configs?->siteName ?: 'Minds';
            $this->mailer->ClearReplyTos();
            $this->mailer->addReplyTo($replyEmail, $siteName);
        }

        $this->mailer->setFrom(
            $message->from['email'],
            $message->from['name'] ?? 'Minds'
        );

        foreach ($message->to as $to) {
            if ($this->filter->isSpam($to['email'])) {
                continue; //don't send to blacklisted domains
            }
            $this->mailer->AddAddress($to['email'], $to['name']);
        }

        $this->mailer->MessageID = $message->messageId;
        $this->mailer->Subject = $message->subject;

        $this->mailer->IsHTML(true);
        $this->mailer->Body = $message->buildHtml();
        $this->mailer->CharSet = 'utf-8';

        if ($this->mailer->Send()) {
            $this->logger->info("Sent email");
            $this->stats['sent']++;
            $this->mailer->ErrorInfo = "";
        } else {
            $this->logger->info("Failed to send email with error: {$this->mailer->ErrorInfo}");
            $this->stats['failed']++;
        }

        return $this;
    }

    public function getStats()
    {
        return $this->stats;
    }

    public function getErrors(): string
    {
        return $this->mailer->ErrorInfo;
    }

    public function __destruct()
    {
        if ($this->mailer) {
            $this->mailer->SmtpClose();
        }
    }
}
