<?php

namespace Psr\Log;

/**
 * Basic Implementation of LoggerAwareInterface.
 */
trait LoggerAwareTrait
{
    /**
     * The logger instance.
     */
    protected ?\Psr\Log\LoggerInterface $logger = null;
    /**
     * Sets a logger.
     */
    public function setLogger(\Psr\Log\LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
