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

use Monolog\Logger;

class PolicyTrigger
{
    const NON_INTENT = 'dm_non_intent';
    const INIT_STATE = 'dm_init';
    const ANY_STATE = 'dm_any';

    /**
     * @var $logger Logger
     */
    private $logger;

    /**
     * @var $policy Policy
     */
    public $policy;

    private $intent;
    private $slots;
    private $changedSlots;
    private $state;

    /**
     * PolicyTrigger constructor.
     * @param $intent
     * @param $slots
     * @param $changedSlots
     * @param $state
     */
    public function __construct($intent, $slots, $changedSlots, $state)
    {
        if (!empty($intent)) {
            $this->intent = $intent;
        } else {
            $this->intent = self::NON_INTENT;
        }
        if (!empty($slots) && is_array($slots)) {
            $this->slots = $slots;
        } else {
            $this->slots = [];
        }
        if (!empty($changedSlots) && is_array($changedSlots)) {
            $this->changedSlots = $changedSlots;
        } else {
            $this->changedSlots = [];
        }

        $this->state = $state;
    }

    /**
     * @param Logger $logger
     * @return PolicyTrigger
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * @return PolicyTriggerScore|bool
     * 给策略打分的工具
     */
    public function hitTrigger()
    {   //首先获取状态 获取意图结果
        $session = $this->policy->policyManager->getSession();
        $quResult = $this->policy->policyManager->getQuResult();
        $score = new PolicyTriggerScore();
        //check intent constraint 意图相同打一分
        if ($this->intent == $quResult->getIntent()) {
            $score->setIntentScore(1);
        }else{
            $this->logger->debug("[Trigger] intent doesn't match, our intent: $this->intent, quResult intent " . $quResult->getIntent());
            return false;
        }

        if(!empty($this->state)){
            //check array state constraint
            $currentState = $session->getSessionObject()->getState();
            if(is_array($this->state)) {//状态包含则打一分
                if(in_array($currentState, $this->state)) {
                    $score->setStateScore(1);
                }else{
                    $this->logger->debug("[Trigger] state doesn't match, required state " . implode(', ', $this->state) . ", current state " . $currentState);
                    return false;
                }
            }

            //check string state constraint
            if (is_string($this->state)) {//状态相同则打一分
                if($this->state == $currentState) {
                    $score->setStateScore(1);
                }else{
                    $this->logger->debug("[Trigger] state doesn't match, required state $this->state, current state " . $currentState);
                    return false;
                }
            }
        }

        //check slots constraint 按槽的重叠个数打分
        if (count($this->slots) === count(array_intersect($this->slots, array_keys($quResult->getSlots())))) {
            $score->setSlotsScore(count($this->slots));
        }else{
            $this->logger->debug("[Trigger] slots doesn't match.");
            return false;
        }

        //check changed slots constraint 按改变的槽内容打分
        if (count($this->changedSlots) === count(array_intersect($this->changedSlots, array_keys($quResult->getChangedSlots())))) {
            $score->setChangedSlotsScore(count($this->changedSlots));
        }else{
            $this->logger->debug("[Trigger] changed slots doesn't match.");
            return false;
        }
        return $score;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        if (is_string($this->state)) {
            $state = $this->state;
        } else {
            $state = json_encode($this->state);
        }
        if (count($this->slots)) {
            $slots = ', slots: ' . json_encode($this->slots);
        } else {
            $slots = '';
        }
        if (count($this->changedSlots)) {
            $changedSlots = ', changed slots: ' . json_encode($this->changedSlots);
        } else {
            $changedSlots = '';
        }
        return "Intent: $this->intent, state: $state" . $slots . $changedSlots;
    }
}
