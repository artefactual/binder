(function () {

  'use strict';

  angular.module('drmc.filters').filter('UtcDate', ['$filter', function ($filter) {

    return function (time, format) {
      if (!angular.isDefined(format)) {
        format = 'MMM d, y';
      }

      var date = new Date(time);
      var _utc = new Date(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate(), date.getUTCHours(), date.getUTCMinutes(), date.getUTCSeconds());

      return $filter('date')(_utc, format);
    };

  }]);

})();
