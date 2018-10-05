<?php
/*
 * This file is part of PHP DNS Server.
 *
 * (c) Yif Swery <yiftachswr@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace yswery\DNS\Tests;

use Symfony\Component\EventDispatcher\EventDispatcher;
use yswery\DNS\ClassEnum;
use yswery\DNS\Header;
use yswery\DNS\Message;
use yswery\DNS\RecordTypeEnum;
use yswery\DNS\Encoder;
use yswery\DNS\ResourceRecord;
use yswery\DNS\Server;
use yswery\DNS\Resolver\JsonResolver;
use PHPUnit\Framework\TestCase;

class ServerTest extends TestCase
{
    /**
     * @var Server
     */
    private $server;

    /**
     * @throws \Exception
     */
    public function setUp()
    {
        $this->server = new Server(
            new JsonResolver([__DIR__.'/Resources/test_records.json']),
            new EventDispatcher()
        );
    }

    /**
     * @param $name
     * @param $type
     * @param $id
     *
     * @return array
     */
    private function encodeQuery($name, $type, $id)
    {
        $qname = Encoder::encodeDomainName($name);
        $flags = 0b0000000000000000;
        $header = pack('nnnnnn', $id, $flags, 1, 0, 0, 0);
        $question = $qname.pack('nn', $type, 1);

        return [$header, $question];
    }

    /**
     * Create a mock query and response pair.
     *
     * @return array
     */
    private function mockQueryAndResponse(): array
    {
        list($queryHeader, $question) = $this->encodeQuery($name = 'test.com.', RecordTypeEnum::TYPE_A, $id = 1337);
        $query = $queryHeader.$question;

        $flags = 0b1000010000000000;
        $qname = Encoder::encodeDomainName($name);
        $header = pack('nnnnnn', $id, $flags, 1, 1, 0, 0);

        $rdata = inet_pton('111.111.111.111');
        $answer = $qname.pack('nnNn', 1, 1, 300, strlen($rdata)).$rdata;

        $response = $header.$question.$answer;

        return [$query, $response];
    }

    /**
     * @throws \yswery\DNS\UnsupportedTypeException
     */
    public function testHandleQueryFromStream()
    {
        list($query, $response) = $this->mockQueryAndResponse();

        $this->assertEquals($response, $this->server->handleQueryFromStream($query));
    }

    /**
     * Tests that the server sends back a "Not implemented" RCODE for a type that has not been implemented, namely "OPT".
     *
     * @throws \yswery\DNS\UnsupportedTypeException
     */
    public function testOptType()
    {
        $q_RR = (new ResourceRecord())
            ->setName('test.com.')
            ->setType(RecordTypeEnum::TYPE_OPT)
            ->setClass(ClassEnum::INTERNET)
            ->setQuestion(true);

        $query = new Message();
        $query->setQuestions([$q_RR])
            ->getHeader()
                ->setQuery(true)
                ->setId($id = 1337);

        $response = new Message();
        $response->setQuestions([$q_RR])
            ->getHeader()
                ->setId($id)
                ->setResponse(true)
                ->setRcode(Header::RCODE_NOT_IMPLEMENTED)
                ->setAuthoritative(true);

        $queryEncoded = Encoder::encodeMessage($query);
        $responseEncoded = Encoder::encodeMessage($response);

        $server = new Server(new DummyResolver(), new EventDispatcher());
        $this->assertEquals($responseEncoded, $server->handleQueryFromStream($queryEncoded));
    }

    public function testOnMessage()
    {
        list($query, $response) = $this->mockQueryAndResponse();
        $this->server->onMessage($query, '127.0.0.1', $socket = new MockSocket());

        $this->assertEquals($response, $socket->getLastTransmission());
    }
}
