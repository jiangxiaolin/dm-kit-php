<?php
// Copyright (c) 2018 Baidu, Inc. All Rights Reserved.
//
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
//
//     http://www.apache.org/licenses/LICENSE-2.0
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.

namespace Baidu\Iov\DmKit\Policy;

use Baidu\Iov\DmKit\Bot\Bot;
use Baidu\Iov\DmKit\Dialog\QuResult;
use Baidu\Iov\DmKit\Exception\DmException;
use Baidu\Iov\DmKit\Parser\ParserInterface;
use Baidu\Iov\DmKit\Policy\Output\PolicyFunctionOutput;
use Baidu\Iov\DmKit\Policy\Output\PolicyOutput;
use Baidu\Iov\DmKit\Policy\Output\PolicyOutputInterface;
use Baidu\Iov\DmKit\Session\AbstractSession;
use Monolog\Logger;

/**
 * Class PolicyManager
 * @package Baidu\Iov\DmKit\Policy
 */
class PolicyManager
{
    private $policyMap;
    private $requestParams;
    private $session;
    private $parser;
    private $botId;

    /**
     * @var $bot Bot
     */
    private $bot;
    /**
     * @var $quResult QuResult
     */
    private $quResult;

    public $logger;

    /**
     * PolicyManager constructor.
     * @param AbstractSession $session
     * @param ParserInterface $parser
     * @param Logger $logger
     */
    public function __construct(AbstractSession $session, ParserInterface $parser, Logger $logger)
    {
        $this->session = $session;
        $this->parser = $parser;
        $this->logger = $logger;
    }

    /**
     * inject request parameters from client side
     *
     * @param mixed $requestParams
     * @return PolicyManager
     * @throws DmException
     */
    public function setRequestParams($requestParams)
    {
        $this->requestParams = $requestParams;
        if (empty($requestParams['cuid'])) {
            throw new DmException('cuid should be set in request params.');
        }
        $this->session->setUuid($requestParams['cuid']);
        $this->bot->setRequestParams($requestParams);
        return $this;
    }

    /**
     * inject nlu results from NLU providers
     *
     * @param $quResults
     * @return $this
     * @throws DmException
     */
    public function setQuResults($quResults)
    {
        $quResultMap = $this->parser->parse($quResults);
        if ($this->botId) {
            $this->quResult = $quResultMap[$this->botId];
            if (!$this->quResult) {
                return $this;
            }
            $this->bot->setQuResult($this->quResult);
        } else {
            throw new DmException('BotId is not set.');
        }
        return $this;
    }

    /**
     * @param Bot $bot
     * @return $this
     */
    public function setBot(Bot $bot)
    {
        $this->bot = $bot;
        return $this;
    }

    /**
     * load the policy parameters and build Policy class
     * 主要的方法：加载策略参数，构建策略类
     * @param $policies
     * @return array
     */
    public function load($policies)
    {
        if (!$this->policyMap) {
            $policyMap = [];
            // 遍历每一个policy：trigger、params、output 分别给每一个policy的三要素构建对象
            foreach ($policies as $policy) {
                $trigger = $policy['trigger'];
                // trigger对象 包含意图 状态 实体等
                $policyTrigger = new PolicyTrigger($trigger['intent'] ?? '', $trigger['slots'] ?? [], $trigger['changed_slots'] ?? [], $trigger['state'] ?? '');
                $policyTrigger->setLogger($this->logger);
                $params = [];
                if(is_array($policy['params'])) {
                    foreach ($policy['params'] as $param) {
                        //找出所有参数
                        $policyParam = new PolicyParam($param['name'], $param['type'], $param['value'], $param['required'] ?? false, $param['options'] ?? []);
                        $params[$param['name']] = $policyParam;
                    }
                }

                $outputs = [];
                // 输出节点PolicyFunctionOutput与PolicyOutput 区别在于多了个function     
                if (is_string($policy['output'])) {
                    $policyOutput = new PolicyFunctionOutput($policy['output']);
                    $outputs[] = $policyOutput->setLogger($this->logger);
                } elseif(is_array($policy['output'])) {
                    foreach ($policy['output'] as $output) {
                        $policyOutput = new PolicyOutput($output['assertion'], $output['session'], $output['result']);
                        $outputs[] = $policyOutput->setLogger($this->logger);
                    }
                }

                $policyObject = new Policy($policyTrigger, $params, $outputs, $this);
                $mapKey = empty($trigger['intent']) ? PolicyTrigger::NON_INTENT : $trigger['intent'];
                if (!isset($policyMap[$mapKey])) {
                    $policyMap[$mapKey] = [];
                }
                //policyMap的key是intent 空的话就是NON_INTENT
                $policyMap[$mapKey][] = $policyObject;

            }
            $this->policyMap = $policyMap;
        }
        return $this->policyMap;
    }

    /**
     * return array result or false
     * if returns false, it means that the query is not recalled
     *  策略的输出函数
     * @param $unitSay
     * @return array|bool
     */
    public function output(&$unitSay = null)
    {
        //no nlu result
        if (!$this->quResult) {
            return false;
        }
        $unitSay = $this->quResult->getSay();
        // 获取当前状态
        $this->session->read();
        $allSlots = $this->session->getSessionObject()->getSlots();
        foreach ($this->quResult->getSlots() as $key => $slot) {
            $allSlots[$key] = $slot;
        }
        $this->quResult->buildChangedSlots($this->session->getSessionObject());
        $this->session->getSessionObject()->setSlots($allSlots);
        //意图不为空 则 清空对话状态？ 原因？
        if (!isset($this->policyMap[$this->quResult->getIntent()])) {
            $this->session->clean();
            $this->logger->debug('Current intent ' . $this->quResult->getIntent() . " doesn't match.");
            return false;
        }
        $this->logger->debug('Current quResult ' . (string)$this->quResult);

        $matchedPolicies = [];
       // 关键步骤  查看匹配到多少policy 以 意图为key 获取policy
        foreach ($this->policyMap[$this->quResult->getIntent()] as $policy) {
            /**
             * @var $policy Policy
             */
            //触发trigger hitTrigger的作用是根据意图对policy进行打分
            $score = $policy->getPolicyTrigger()->hitTrigger();
            if ($score) {
                $this->logger->debug('Policy matched. Trigger: ' . (string)$policy->getPolicyTrigger());
                $matchedPolicies[] = [
                    'score' => $score,
                    'policy' => $policy,
                ];
            }
        }

        //choose a policy with highest score
        //选择最高分的策略  只选一个 所以不存在互斥的问题
        if(count($matchedPolicies)) {
            usort($matchedPolicies, function($a, $b){
                return $a['score']->isGreaterThan($b['score']) ? -1 : 1;
            });
            $policy = $matchedPolicies[0]['policy'];
            $this->logger->debug('Policy chosen. Trigger: ' . (string)$policy->getPolicyTrigger());
            // 根据选定的策略 遍历输出结果  并同时更新状态
            foreach ($policy->getPolicyOutputs() as $policyOutput) {
                /**
                 * @var $policyOutput PolicyOutputInterface
                 */
                $output = $policyOutput->output($this->session);
                if (false === $output) {
                    continue;
                }
                $this->session->write();
                return $output;
            }
            $this->logger->debug('Nothing to output.');
        }
        //初始状态的话则不
        //do not retry on initial state 
        if ($this->session->getSessionObject()->getState() === PolicyTrigger::INIT_STATE) {
            return false;
        }
        $retry = $this->bot->retry();
        $this->session->write();
        return $retry;
    }

    /**
     * @return mixed
     */
    public function getBotId()
    {
        return $this->botId;
    }

    /**
     * @param mixed $botId
     * @return PolicyManager
     */
    public function setBotId($botId)
    {
        $this->botId = $botId;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getRequestParams()
    {
        return $this->requestParams;
    }

    /**
     * @return AbstractSession
     */
    public function getSession()
    {
        return $this->session;
    }

    /**
     * @return QuResult
     */
    public function getQuResult()
    {
        return $this->quResult;
    }

    /**
     * @return Bot
     */
    public function getBot()
    {
        return $this->bot;
    }

}
