require('events').EventEmitter.defaultMaxListeners = 1000;

var deployd = require('deployd')
  , options = {port: 2403};

var dpd = deployd(options);

dpd.listen();
