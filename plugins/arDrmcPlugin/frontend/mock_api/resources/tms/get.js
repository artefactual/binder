var urlParts = url.split('/'),
    mainRoutes = {},
    criteria = {},
    filterFields = ['ObjectNumber', 'ObjectID', 'Component'];

// remove empty first element
urlParts.shift();

// set criteria using valid filter fields
filterFields.forEach(function(field) {
  if (typeof query[field] != 'undefined') {
    criteria[field] = query[field];
  }
});

var objectDetailRequestHandler = function() {
  var results = [];

  criteria.limit = 1;
  // fetch TMS objects matching criteria
  dpd.tmsraw.get(criteria, function(tmsObjects) {
    tmsObjects.forEach(function(tmsObject) {
      results.push(tmsObject);
    });

    // Manually add random Thumbnail. Tried several fixes but
    // this field and others get lost while saving to MongoDB
    var result = results[0];
    result.Thumbnail = 'https://unsplash.it/125?random';
    result.FullImage = 'https://unsplash.it/250?random';

    setResult({
      'GetTombstoneDataRestIdResult': result
    });
  });
};

// HTTP GET /tms/GetTombstoneData
mainRoutes.GetTombstoneData = objectDetailRequestHandler;

// HTTP GET /tms/GetTombstoneDataRest
mainRoutes.GetTombstoneDataRest = objectDetailRequestHandler;

// HTTP GET /tms/GetComponentDetails
mainRoutes.GetComponentDetails = function() {
  var results = [];

  criteria.limit = 1;
  // fetch TMS objects matching criteria
  dpd.tmscomponentraw.get(criteria, function(tmsComponents) {
    tmsComponents.forEach(function(tmsComponent) {
      results.push(tmsComponent);
    });
    setResult({
      'GetComponentDetailsResult': results[0]
    });
  });
};

mainRoutes.GetTombstoneDateId = function() {};
mainRoutes.GetObjectID = function() {};
mainRoutes.GetObjectPackageID = function() {};
mainRoutes.GetObjectPackage = function() {};
mainRoutes.GetObjectPackageTitle = function() {};
mainRoutes.GetExhibitionObjects = function() {};

if (typeof mainRoutes[urlParts[0]] != 'undefined') {
  resultData = mainRoutes[urlParts[0]]();
} else {
  resultData = {'message': 'Bad URL.'};
}
