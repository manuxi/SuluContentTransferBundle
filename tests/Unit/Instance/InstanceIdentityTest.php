<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Instance;

use Manuxi\SuluContentTransferBundle\Instance\InstanceIdentity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class InstanceIdentityTest extends TestCase
{
    public function testDefaultIdIsAHashOfTheSecretWithSixteenCharacters(): void
    {
        $id = (new InstanceIdentity('secret-one'))->getId();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $id);
        $this->assertSame($id, (new InstanceIdentity('secret-one'))->getId());
        $this->assertNotSame($id, (new InstanceIdentity('secret-two'))->getId());
        $this->assertStringNotContainsString('secret-one', $id);
    }

    public function testConfiguredIdWins(): void
    {
        $this->assertSame('my-system.1', (new InstanceIdentity('secret', ' my-system.1 '))->getId());
    }

    public function testEmptyConfiguredIdFallsBackToTheHash(): void
    {
        $this->assertSame((new InstanceIdentity('secret'))->getId(), (new InstanceIdentity('secret', ''))->getId());
    }

    public function testConfiguredIdWithUnsuitableCharactersIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new InstanceIdentity('secret', 'no spaces/allowed'))->getId();
    }

    public function testLabelIsTheHostOfTheRequest(): void
    {
        $stack = new RequestStack();
        $stack->push(Request::create('https://local.example.org/admin'));

        $this->assertSame('local.example.org', (new InstanceIdentity('secret', null, null, $stack))->getLabel());
    }

    public function testLabelIsCliWithoutARequest(): void
    {
        $this->assertSame('cli', (new InstanceIdentity('secret', null, null, new RequestStack()))->getLabel());
        $this->assertSame('cli', (new InstanceIdentity('secret'))->getLabel());
    }

    public function testConfiguredLabelWins(): void
    {
        $stack = new RequestStack();
        $stack->push(Request::create('https://local.example.org/'));

        $this->assertSame('Production', (new InstanceIdentity('secret', null, 'Production', $stack))->getLabel());
    }
}
