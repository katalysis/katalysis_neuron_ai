# Katalysis Neuron AI Package for Concrete CMS

A Concrete CMS package that integrates the NeuronAI PHP framework, providing AI capabilities with built-in observability and logging that's compatible with Concrete CMS.

## Features

- **AI Framework Integration**: Bundles the NeuronAI PHP framework for AI/LLM interactions
- **Concrete CMS Compatibility**: Adapted logging system that works seamlessly with Concrete CMS
- **RAG (Retrieval-Augmented Generation) Support**: Built-in vector store and document retrieval capabilities
- **Multiple AI Provider Support**: OpenAI, Anthropic, and Ollama integration
- **Observability**: Built-in monitoring and logging through Inspector APM
- **Vector Store**: File-based vector storage for document embeddings and similarity search

## Purpose

This package serves as the foundation layer for AI functionality in Concrete CMS applications, providing the core NeuronAI framework with logging adaptations that resolve PSR-3 interface conflicts between the original NeuronAI package and Concrete CMS.

## Dependencies

- Concrete CMS 9.3+
- PHP 8.1+
- NeuronAI framework (katalysis/neuron-ai)

