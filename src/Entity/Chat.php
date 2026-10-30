<?php

namespace Katalysis\NeuronAi\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Neuron AI Chat Entity
 * One chat; its messages are rows in KatalysisNeuronAiChatMessages (ChatMessage, P2.5).
 *
 * P3.9-1: the table is Doctrine's alone (the package controller created it too, with other columns and indexes). The
 * indexes are the ones that SQL had: the session's chat is looked up on every panel request, the chat list is by user,
 * and P3.9-2 purges by date: idx_updated_date, since a chat's age is its last activity.
 *
 * @ORM\Entity
 * @ORM\Table(
 *     name="KatalysisNeuronAiChats",
 *     indexes={
 *         @ORM\Index(name="idx_session", columns={"sessionId"}),
 *         @ORM\Index(name="idx_created_by", columns={"createdBy"}),
 *         @ORM\Index(name="idx_created_date", columns={"createdDate"}),
 *         @ORM\Index(name="idx_updated_date", columns={"updatedDate"})
 *     }
 * )
 */
class Chat
{
    /**
     * @var integer
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @ORM\Column(name="`id`", type="integer")
     */
    protected $id;

    /**
     * @var string
     * @ORM\Column(name="`sessionId`", type="string", nullable=true)
     */
    protected $sessionId;

    /**
     * @var string
     * @ORM\Column(name="`firstMessage`", type="text", nullable=true)
     */
    protected $firstMessage = '';

    /**
     * @var string
     * @ORM\Column(name="`lastMessage`", type="text", nullable=true)
     */
    protected $lastMessage = '';

    /**
     * @var integer
     * @ORM\Column(name="`userMessageCount`", type="integer")
     */
    protected $userMessageCount = 0;

    /**
     * @var integer
     * @ORM\Column(name="`createdBy`", type="integer", nullable=true)
     */
    protected $createdBy;

    /**
     * @var \DateTime
     * @ORM\Column(name="`createdDate`", type="datetime", nullable=true)
     */
    protected $createdDate;

    /**
     * @var \DateTime
     * @ORM\Column(name="`updatedDate`", type="datetime", nullable=true)
     */
    protected $updatedDate;

    /**
     * @var \DateTime
     * @ORM\Column(name="`started`", type="datetime", nullable=true)
     */
    protected $started;

    /**
     * @var string
     * @ORM\Column(name="`location`", type="string", length=500, nullable=true)
     */
    protected $location = '';

    /**
     * Get id
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Get session ID
     */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * Set session ID
     */
    public function setSessionId(?string $sessionId): self
    {
        $this->sessionId = $sessionId;
        return $this;
    }

    /**
     * Get first message
     */
    public function getFirstMessage(): string
    {
        return $this->firstMessage ?? '';
    }

    /**
     * Set first message
     */
    public function setFirstMessage(string $message): self
    {
        $this->firstMessage = $message;
        return $this;
    }

    /**
     * Get last message
     */
    public function getLastMessage(): string
    {
        return $this->lastMessage ?? '';
    }

    /**
     * Set last message
     */
    public function setLastMessage(string $message): self
    {
        $this->lastMessage = $message;
        return $this;
    }

    /**
     * Get user message count
     */
    public function getUserMessageCount(): int
    {
        return $this->userMessageCount ?? 0;
    }

    /**
     * Set user message count
     */
    public function setUserMessageCount(int $count): self
    {
        $this->userMessageCount = $count;
        return $this;
    }

    /**
     * Get created by user ID
     */
    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    /**
     * Set created by user ID
     */
    public function setCreatedBy(?int $userId): self
    {
        $this->createdBy = $userId;
        return $this;
    }

    /**
     * Get created date
     */
    public function getCreatedDate(): ?\DateTime
    {
        return $this->createdDate;
    }

    /**
     * Set created date
     */
    public function setCreatedDate(?\DateTime $date): self
    {
        $this->createdDate = $date;
        return $this;
    }

    /**
     * Get updated date
     */
    public function getUpdatedDate(): ?\DateTime
    {
        return $this->updatedDate;
    }

    /**
     * Set updated date
     */
    public function setUpdatedDate(?\DateTime $date): self
    {
        $this->updatedDate = $date;
        return $this;
    }

    /**
     * Get started timestamp
     */
    public function getStarted(): ?\DateTime
    {
        return $this->started;
    }

    /**
     * Set started timestamp
     */
    public function setStarted(?\DateTime $date): self
    {
        $this->started = $date;
        return $this;
    }

    /**
     * Get location (page path where chat started)
     */
    public function getLocation(): string
    {
        return $this->location ?? '';
    }

    /**
     * Set location
     */
    public function setLocation(string $location): self
    {
        $this->location = $location;
        return $this;
    }
}
