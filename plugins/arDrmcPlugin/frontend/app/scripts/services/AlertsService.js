(function () {

  'use strict';

  angular.module('drmc.services').service('AlertsService', function ($rootScope, $timeout, $filter) {

    $rootScope.alerts = [];
    $rootScope.alertId = 0;

    // Remove all alerts on location change
    $rootScope.$on('$locationChangeSuccess', function () {
      $rootScope.alerts = [];
      $rootScope.alertId = 0;
    });

    return {

      addAlert: function (options) {
        var self = this;
        var id = ++$rootScope.alertId;
        if (!angular.isDefined(options.type)) {
          options.type = 'warning';
        }
        $rootScope.alerts.push({
          id: id,
          type: options.type,
          message: options.message,
          strongMessage: options.strongMessage,
          close: function () {
            return self.closeAlert(id);
          }
        });

        if (angular.isNumber(options.timeout)) {
          $timeout(function () {
            self.closeAlert(id);
          }, options.timeout);
        }

        return id;
      },

      closeAlert: function (id) {
        return this.removeAlertByIndex(
          $rootScope.alerts.indexOf(
            $filter('filter')($rootScope.alerts, {id: id}, true)[0]
          )
        );
      },

      removeAlertByIndex: function (index) {
        return $rootScope.alerts.splice(index, 1);
      }

    };

  });

})();
