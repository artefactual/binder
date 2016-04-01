(function () {

  'use strict';

  angular.module('drmc.services').service('ModalDownloadService', function ($modal, $window, SETTINGS, $http, AlertsService) {

    var configuration = {
      templateUrl: SETTINGS.viewsPath + '/modals/download-aip-or-aip-file.html',
      backdrop: true,
      resolve: {},
      controller: function ($scope, $document, $window, $modalInstance, downloadDescription, title) {
        // Hack transclude problem in modals/angularjs, see:
        // https://github.com/angular-ui/bootstrap/issues/969#issuecomment-31875867
        // https://github.com/angular-ui/bootstrap/issues/969#issuecomment-33128068
        $scope.modalContainer = {};

        $scope.minLength = 10;

        // Resolved
        $scope.downloadDescription = downloadDescription;
        $scope.title = title;

        $scope.submit = function () {
          if (!$scope.modalContainer.form.$valid) {
            return;
          }
          $modalInstance.close($scope.modalContainer.reason);
        };

        $scope.cancel = function () {
          $modalInstance.dismiss('cancel');
        };
      }
    };

    var open = function (aip, uuid, fileId) {
      return $modal.open(configuration).result.then(function (reason) {
        var params = {
          reason: reason
        };
        if (angular.isDefined(fileId)) {
          params.file_id = fileId;
        }
        return $http({
          method: 'GET',
          url: SETTINGS.frontendPath + 'api/aips/' + uuid + '/downloadCheck',
          params: params
        }).then(function (response) {
          if (response.data.available) {
            var url = SETTINGS.frontendPath + 'api/aips/download' +
              '?url=' + $window.encodeURIComponent(response.data.url) +
              '&filename=' + $window.encodeURIComponent(response.data.filename) +
              '&filesize=' + $window.encodeURIComponent(response.data.filesize);
            $window.open(url, '_self');
          } else {
            var downloadType = 'AIP';
            if (angular.isDefined(fileId)) {
              downloadType = 'file';
            }
            var alertOptions = {
              type: 'error',
              message: response.data.reason,
              strongMessage: 'Could not download ' + downloadType + '!'
            };
            AlertsService.addAlert(alertOptions);
          }
        });
      });
    };

    this.downloadFile = function (aip, uuid, fileId, fileDescription) {
      configuration.resolve.title = function () {
        return 'Download file';
      };
      configuration.resolve.downloadDescription = function () {
        return fileDescription;
      };
      return open(aip, uuid, fileId);
    };

    this.downloadAip = function (aip, uuid) {
      configuration.resolve.title = function () {
        return 'Download AIP';
      };
      configuration.resolve.downloadDescription = function () {
        return 'AIP ' + aip + ' (' + uuid + ')';
      };
      return open(aip, uuid);
    };

  });

})();
