<?php

namespace Katalysis\NeuronAi\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One tool call that waited for the user's approval (P3.6-1): what the user was shown, the state it was computed from,
 * and what they decided. Deleting the chat deletes its rows (the foreign key cascades).
 *
 * Read and written by ApprovalService through the database connection; this entity defines the table, so Concrete
 * creates and updates it with the package (c5:package:update).
 *
 * @ORM\Entity
 * @ORM\Table(
 *     name="KatalysisNeuronAiApprovals",
 *     uniqueConstraints={@ORM\UniqueConstraint(name="chat_call", columns={"chatId", "callId"})},
 *     indexes={@ORM\Index(name="chat_status", columns={"chatId", "status"})}
 * )
 */
class Approval
{
    /**
     * @var int
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @ORM\Column(name="id", type="bigint")
     */
    protected $id;

    /**
     * @var Chat
     * @ORM\ManyToOne(targetEntity="Chat")
     * @ORM\JoinColumn(name="chatId", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    protected $chat;

    /**
     * Neuron's tool call ID, compared exactly.
     *
     * @var string
     * @ORM\Column(name="callId", type="string", length=64, options={"collation": "utf8mb4_bin"})
     */
    protected $callId;

    /**
     * @var string
     * @ORM\Column(name="toolName", type="string", length=64)
     */
    protected $toolName;

    /**
     * JSON: the call's inputs, redacted
     *
     * @var string
     * @ORM\Column(name="inputs", type="text")
     */
    protected $inputs;

    /**
     * JSON: the change entry the user was shown (ChangeEntry)
     *
     * @var string
     * @ORM\Column(name="preview", type="text")
     */
    protected $preview;

    /**
     * sha256 of the state the preview was computed from
     *
     * @var string
     * @ORM\Column(name="fingerprint", type="string", length=64, options={"fixed": true})
     */
    protected $fingerprint;

    /**
     * pending, approved, denied, stale or not_approved
     *
     * @var string
     * @ORM\Column(name="status", type="string", length=16)
     */
    protected $status;

    /**
     * The user's reason for a Deny, or why the call was not approved
     *
     * @var string|null
     * @ORM\Column(name="note", type="text", nullable=true)
     */
    protected $note;

    /**
     * @var int
     * @ORM\Column(name="requestedBy", type="integer")
     */
    protected $requestedBy;

    /**
     * @var \DateTime
     * @ORM\Column(name="requestedAt", type="datetime")
     */
    protected $requestedAt;

    /**
     * @var int|null
     * @ORM\Column(name="decidedBy", type="integer", nullable=true)
     */
    protected $decidedBy;

    /**
     * @var \DateTime|null
     * @ORM\Column(name="decidedAt", type="datetime", nullable=true)
     */
    protected $decidedAt;

    /**
     * When the batch's decisions went to Neuron (all at once, once every call in it was decided). Null until then.
     *
     * @var \DateTime|null
     * @ORM\Column(name="submittedAt", type="datetime", nullable=true)
     */
    protected $submittedAt;
}
