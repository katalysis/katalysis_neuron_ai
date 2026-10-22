<?php

namespace Katalysis\NeuronAi\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One message of a chat (P2.5): the table behind ChatMessageStore. The Chat row is the thread "chat-<id>"; deleting it
 * deletes its messages (the foreign key cascades).
 *
 * The rows are read and written by ChatMessageStore through the database connection, not through the entity manager.
 * This entity defines the table, so Concrete creates and updates it with the package (c5:package:update).
 *
 * @ORM\Entity
 * @ORM\Table(
 *     name="KatalysisNeuronAiChatMessages",
 *     uniqueConstraints={@ORM\UniqueConstraint(name="chat_message", columns={"chatId", "messageId"})},
 *     indexes={@ORM\Index(name="chat_active", columns={"chatId", "archivedAt"})}
 * )
 */
class ChatMessage
{
    /**
     * Insertion order, which is the thread's order. Messages are never ordered by createdAt.
     *
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
     * Neuron's message ID (msg_...). Compared exactly: a binary collation, as Neuron's SQLMessageStore advises.
     *
     * @var string
     * @ORM\Column(name="messageId", type="string", length=64, options={"collation": "utf8mb4_bin"})
     */
    protected $messageId;

    /**
     * user or assistant
     *
     * @var string
     * @ORM\Column(name="role", type="string", length=16)
     */
    protected $role;

    /**
     * tool_call, tool_call_result, or null for a plain message
     *
     * @var string|null
     * @ORM\Column(name="type", type="string", length=32, nullable=true)
     */
    protected $type;

    /**
     * JSON: the message's content blocks
     *
     * @var string|null
     * @ORM\Column(name="content", type="text", nullable=true)
     */
    protected $content;

    /**
     * JSON: the rest of the message as Neuron serializes it (__meta, usage, tools)
     *
     * @var string|null
     * @ORM\Column(name="meta", type="text", nullable=true)
     */
    protected $meta;

    /**
     * ChatMessageStore::FORMAT_VERSION when the row was written
     *
     * @var int
     * @ORM\Column(name="formatVersion", type="smallint")
     */
    protected $formatVersion;

    /**
     * Set when the message has been trimmed out of the model's context; the transcript keeps it
     *
     * @var \DateTime|null
     * @ORM\Column(name="archivedAt", type="datetime", nullable=true)
     */
    protected $archivedAt;

    /**
     * @var \DateTime
     * @ORM\Column(name="createdAt", type="datetime")
     */
    protected $createdAt;
}
