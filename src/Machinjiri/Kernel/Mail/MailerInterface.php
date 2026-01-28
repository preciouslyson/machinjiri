<?php

namespace Mlangeni\Machinjiri\Core\Kernel\Mail;

/**
 * MailerInterface defines the contract for sending emails
 * 
 * All mailer implementations must follow this contract to ensure
 * consistent email sending across the application.
 */
interface MailerInterface
{
    /**
     * Set sender address
     * 
     * @param string $email Sender email address
     * @param string|null $name Sender name
     * @return self Fluent interface
     */
    public function from(string $email, ?string $name = null): self;

    /**
     * Set recipient address
     * 
     * @param string|array $email Recipient email or array of emails
     * @param string|null $name Recipient name (if email is string)
     * @return self Fluent interface
     */
    public function to($email, ?string $name = null): self;

    /**
     * Add a CC recipient
     * 
     * @param string|array $email CC email or array of emails
     * @param string|null $name Recipient name (if email is string)
     * @return self Fluent interface
     */
    public function cc($email, ?string $name = null): self;

    /**
     * Add a BCC recipient
     * 
     * @param string|array $email BCC email or array of emails
     * @param string|null $name Recipient name (if email is string)
     * @return self Fluent interface
     */
    public function bcc($email, ?string $name = null): self;

    /**
     * Set reply-to address
     * 
     * @param string $email Reply-to email address
     * @param string|null $name Reply-to name
     * @return self Fluent interface
     */
    public function replyTo(string $email, ?string $name = null): self;

    /**
     * Set email subject
     * 
     * @param string $subject Email subject
     * @return self Fluent interface
     */
    public function subject(string $subject): self;

    /**
     * Set HTML body
     * 
     * @param string $body HTML email body
     * @return self Fluent interface
     */
    public function html(string $body): self;

    /**
     * Set plain text body
     * 
     * @param string $body Plain text email body
     * @return self Fluent interface
     */
    public function text(string $body): self;

    /**
     * Attach a file
     * 
     * @param string $path File path
     * @param string|null $name Attachment name
     * @param string|null $type MIME type
     * @return self Fluent interface
     */
    public function attach(string $path, ?string $name = null, ?string $type = null): self;

    /**
     * Remove an attachment
     * 
     * @param string $name Attachment name
     * @return self Fluent interface
     */
    public function removeAttachment(string $name): self;

    /**
     * Embed an image
     * 
     * @param string $path Image path
     * @param string $cid Content ID for embedding
     * @return self Fluent interface
     */
    public function embed(string $path, string $cid): self;

    /**
     * Set priority level
     * 
     * @param int $level Priority level (1-5)
     * @return self Fluent interface
     */
    public function priority(int $level): self;

    /**
     * Send the email
     * 
     * @return bool True if email sent successfully
     */
    public function send(): bool;

    /**
     * Get error message
     * 
     * @return string|null Error message if any
     */
    public function getError(): ?string;

    /**
     * Get all recipients
     * 
     * @return array All recipients
     */
    public function getRecipients(): array;

    /**
     * Clear all recipients
     * 
     * @return self Fluent interface
     */
    public function clearRecipients(): self;

    /**
     * Clear all attachments
     * 
     * @return self Fluent interface
     */
    public function clearAttachments(): self;

    /**
     * Reset mailer to initial state
     * 
     * @return self Fluent interface
     */
    public function reset(): self;

    /**
     * Check if email is valid
     * 
     * @param string $email Email address to validate
     * @return bool True if valid
     */
    public static function isValidEmail(string $email): bool;
}
