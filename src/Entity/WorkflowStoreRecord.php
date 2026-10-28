<?php

namespace Katalysis\NeuronAi\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Neuron 4's workflow run state (P3.6-1): a turn paused for approval waits here between requests. The table is
 * exactly the schema Neuron's DatabasePersistence documents (vendor/neuron-core/neuron-ai/upgrade/14-workflow-persistence.md):
 * identifiers hex-encoded and values base64-encoded by Neuron, so ASCII columns. Neuron deletes a run's partition when
 * the run completes.
 *
 * Neuron reads and writes the rows through its own PDO queries. This entity only defines the table, so Concrete
 * creates and updates it with the package (c5:package:update), as ChatMessage does.
 *
 * @ORM\Entity
 * @ORM\Table(name="KatalysisNeuronAiWorkflowStore")
 */
class WorkflowStoreRecord
{
    /**
     * @var string
     * @ORM\Id
     * @ORM\Column(name="`partition`", type="string", length=510, options={"charset": "ascii", "collation": "ascii_bin"})
     */
    protected $partition;

    /**
     * @var string
     * @ORM\Id
     * @ORM\Column(name="`key`", type="string", length=510, options={"charset": "ascii", "collation": "ascii_bin"})
     */
    protected $key;

    /**
     * @var string
     * @ORM\Column(name="`value`", type="text", options={"charset": "ascii"})
     */
    protected $value;

    /**
     * @var \DateTime
     * @ORM\Column(name="updated_at", type="datetime", columnDefinition="TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP")
     */
    protected $updatedAt;
}
