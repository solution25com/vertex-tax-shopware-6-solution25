<?php

declare(strict_types=1);

namespace VertexTax\Core\Tax;

class TaxCalculatorRegistry
{
    /**
     * @var TaxCalculatorInterface[]
     */
    private array $calculators = [];

    /**
     * @param TaxCalculatorInterface[] $calculators
     */
    public function __construct(iterable $calculators)
    {
        foreach ($calculators as $calculator) {
            $this->calculators[] = $calculator;
        }
    }

    /**
     * Get calculator for base class
     *
     * @param string $baseClass
     * @return TaxCalculatorInterface|null
     */
    public function getCalculatorFor(string $baseClass): ?TaxCalculatorInterface
    {
        foreach ($this->calculators as $calculator) {
            if ($calculator->supports($baseClass)) {
                return $calculator;
            }
        }

        return null;
    }
}
