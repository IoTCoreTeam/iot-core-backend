flowchart TD
  start([Start]) --> init[Init events + record workflow_start]
  init --> loadDef[Load workflow definition]
  loadDef --> defOk{Definition valid?}
  defOk -- No --> errDef[[Throw: definition empty]]
  defOk -- Yes --> fetchStatus[Fetch device status]
  fetchStatus --> recordStatus[Record device_status_fetched]
  recordStatus --> checkOnline[Assert devices online]
  checkOnline --> devicesOk{All required devices online?}
  devicesOk -- No --> errOffline[[Throw: device offline]]
  devicesOk -- Yes --> ensureOff[Ensure workflow devices off]
  ensureOff --> offOk{All devices off?}
  offOk -- No --> errOff[[Throw: failed to turn off devices]]

  offOk -- Yes --> executeFlow[Execute flow]
  executeFlow --> findSE[Find start/end node]
  findSE --> seOk{Start & End exist?}
  seOk -- No --> errSE[[Throw: missing start/end]]

  seOk -- Yes --> loopStart{{Loop: current node}}
  loopStart --> maxStep{Steps > maxSteps?}
  maxStep -- Yes --> errLoop[[Throw: exceeded max steps]]

  maxStep -- No --> isEnd{Current == end?}
  isEnd -- Yes --> recordEnd[Record workflow_end_reached]
  recordEnd --> doneSuccess[Return completed + events]

  isEnd -- No --> nodeFound{Node exists?}
  nodeFound -- No --> errNode[[Throw: node not found]]

  nodeFound -- Yes --> recordEnter[Record node_enter]
  recordEnter --> typeCheck{Node type}

  typeCheck -- action --> runAction[Run action node]
  runAction --> nextAction[Resolve next node (no branch)]
  nextAction --> loopStart

  typeCheck -- condition --> evalCond[Evaluate condition]
  evalCond --> branch[Branch true/false]
  branch --> nextCond[Resolve next node by branch]
  nextCond --> loopStart

  typeCheck -- other --> nextOther[Resolve next node]
  nextOther --> loopStart

  %% Error handling / rollback
  errDef --> fail[Record workflow_failed]
  errOffline --> fail
  errOff --> fail
  errSE --> fail
  errLoop --> fail
  errNode --> fail

  fail --> rollback[Abort workflow devices (force off)]
  rollback --> rethrow[[Throw error]]
