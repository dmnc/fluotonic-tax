<?php

namespace CommerceGuys\Tax\Tests\Resolver;

use CommerceGuys\Addressing\AddressInterface;
use CommerceGuys\Tax\Repository\TaxTypeRepository;
use CommerceGuys\Tax\Resolver\TaxType\CanadaTaxTypeResolver;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \CommerceGuys\Tax\Resolver\TaxType\CanadaTaxTypeResolver
 */
class CanadaTaxTypeResolverTest extends TestCase
{
    /**
     * Known tax types.
     *
     * @var array
     */
    protected $taxTypes = [
        'ca_on_hst' => [
            'name' => 'Ontario HST',
            'generic_label' => 'hst',
            'tag' => 'CA',
            'zone' => 'ca_on',
            'rates' => [
                [
                    'id' => 'ca_on_hst',
                    'name' => 'Ontario HST',
                    'amounts' => [
                        [
                            'id' => 'ca_on_hst_13',
                            'amount' => 0.13,
                            'start_date' => '2010-07-01',
                        ],
                    ],
                ],
            ],
        ],
        'ca_ns_hst' => [
            'name' => 'Nova Scotia HST',
            'generic_label' => 'hst',
            'tag' => 'CA',
            'zone' => 'ca_ns',
            'rates' => [
                [
                    'id' => 'ca_ns_hst',
                    'name' => 'Nova Scotia HST',
                    'amounts' => [
                        [
                            'id' => 'ca_ns_hst_15',
                            'amount' => 0.15,
                            'start_date' => '2010-07-01',
                        ],
                    ],
                ],
            ],
        ],
    ];

    /**
     * Known zones.
     *
     * @var array
     */
    protected $zones = [
        'ca_on' => [
            'name' => 'Ontario (HST)',
            'members' => [
                [
                    'type' => 'country',
                    'id' => 'ca_on',
                    'name' => 'Canada - Ontario',
                    'country_code' => 'CA',
                    'administrative_area' => 'ON',
                ],
            ],
        ],
        'ca_ns' => [
            'name' => 'Nova Scotia (HST)',
            'members' => [
                [
                    'type' => 'country',
                    'id' => 'ca_ns',
                    'name' => 'Canada - Nova Scotia',
                    'country_code' => 'CA',
                    'administrative_area' => 'NS',
                ],
            ],
        ],
    ];

    /**
     * @covers ::__construct
     *
     * @uses \CommerceGuys\Tax\Repository\TaxTypeRepository
     */
    protected function createResolver()
    {
        $root = vfsStream::setup('resources');
        $directory = vfsStream::newDirectory('tax_type')->at($root);
        foreach ($this->taxTypes as $id => $definition) {
            $filename = $id . '.json';
            vfsStream::newFile($filename)->at($directory)->setContent(json_encode($definition));
        }
        $directory = vfsStream::newDirectory('zone')->at($root);
        foreach ($this->zones as $id => $definition) {
            $filename = $id . '.json';
            vfsStream::newFile($filename)->at($directory)->setContent(json_encode($definition));
        }

        $taxTypeRepository = new TaxTypeRepository('vfs://resources/');
        $resolver = new CanadaTaxTypeResolver($taxTypeRepository);

        return $resolver;
    }

    /**
     * @covers ::resolve
     * @covers ::getTaxTypes
     *
     * @uses \CommerceGuys\Tax\Resolver\TaxType\StoreRegistrationCheckerTrait
     * @uses \CommerceGuys\Tax\Repository\TaxTypeRepository
     * @uses \CommerceGuys\Tax\Model\TaxType
     * @uses \CommerceGuys\Tax\Model\TaxRate
     * @uses \CommerceGuys\Tax\Model\TaxRateAmount
     */
    public function testResolver()
    {
        $resolver = $this->createResolver();

        $taxable = $this
            ->getMockBuilder('CommerceGuys\Tax\TaxableInterface')
            ->getMock();
        $usAddress = $this->createStub('CommerceGuys\Addressing\Address');
        $usAddress
            ->method('getCountryCode')
            ->willReturn('US')
            ;
        $usAddress
            ->method('getPostalCode')
            ->willReturn('')
            ;
        $ontarioAddress = $this->createStub('CommerceGuys\Addressing\Address');
        $ontarioAddress
            ->method('getCountryCode')
            ->willReturn('CA')
            ;
        $ontarioAddress
            ->method('getPostalCode')
            ->willReturn('')
            ;
        $ontarioAddress
            ->method('getAdministrativeArea')
            ->willReturn('ON');
        $novaScotiaAddress = $this->createStub('CommerceGuys\Addressing\Address');
        $novaScotiaAddress
            ->method('getCountryCode')
            ->willReturn('CA')
            ;
        $novaScotiaAddress
            ->method('getPostalCode')
            ->willReturn('')
            ;
        $novaScotiaAddress
            ->method('getAdministrativeArea')
            ->willReturn('NS');

        // Nova Scotia store, Ontario customer.
        $context = $this->getContext($ontarioAddress, $novaScotiaAddress);
        $results = $resolver->resolve($taxable, $context);
        $result = reset($results);
        $this->assertInstanceOf('CommerceGuys\Tax\Model\TaxType', $result);
        $this->assertEquals('ca_on_hst', $result->getId());

        // Ontario store, Nova Scotia customer.
        $context = $this->getContext($novaScotiaAddress, $ontarioAddress);
        $results = $resolver->resolve($taxable, $context);
        $result = reset($results);
        $this->assertInstanceOf('CommerceGuys\Tax\Model\TaxType', $result);
        $this->assertEquals('ca_ns_hst', $result->getId());

        // Ontario store, US customer.
        $context = $this->getContext($usAddress, $ontarioAddress);
        $result = $resolver->resolve($taxable, $context);
        $this->assertEquals([], $result);

        // US store, Ontario customer.
        $context = $this->getContext($ontarioAddress, $usAddress);
        $result = $resolver->resolve($taxable, $context);
        $this->assertEquals([], $result);
    }

    /**
     * Returns a mock context based on the provided data.
     *
     * @param AddressInterface $customerAddress    The customer address.
     * @param AddressInterface $storeAddress       The store address.
     * @param array            $storeRegistrations The store registrations.
     *
     * @return \CommerceGuys\Tax\Resolver\Context
     */
    protected function getContext($customerAddress, $storeAddress, $storeRegistrations = [])
    {
        $context = $this
            ->getMockBuilder('CommerceGuys\Tax\Resolver\Context')
            ->disableOriginalConstructor()
            ->getMock();
        $context->expects($this->any())
            ->method('getCustomerAddress')
            ->will($this->returnValue($customerAddress));
        $context->expects($this->any())
            ->method('getStoreAddress')
            ->will($this->returnValue($storeAddress));
        $context->expects($this->any())
            ->method('getStoreRegistrations')
            ->will($this->returnValue($storeRegistrations));

        return $context;
    }
}
