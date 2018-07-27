import './search.html';
import {Searches} from "../../api/searches/searches";
import { Shows } from '/imports/api/shows/shows.js';
import '/imports/ui/components/image.js';
import moment from 'moment-timezone';

Template.pages_search.onCreated(function() {
  // Set page variables
  Session.set('BreadCrumbs', JSON.stringify([]));
  Session.set('PageTitle', 'Browse Anime');

  // Local variables
  this.limitIncrement = 20;

  this.state = new ReactiveDict();
  this.state.setDefault({
    searchLimit: this.limitIncrement
  });

  // Local functions
  this.getSearchOptions = function() {
    FlowRouter.watchPathChange();
    return FlowRouter.current().queryParams;
  };

  this.isSearching = function() {
    let currentSearch = Searches.queryWithSearch(this.getSearchOptions()).fetch()[0];
    return currentSearch && currentSearch.locked();
  };

  this.canLoadMoreShows = function() {
    return Shows.querySearch(this.getSearchOptions(), this.state.get('searchLimit'), getStorageItem('SelectedTranslationType')).count() >= this.state.get('searchLimit');
  };

  this.moveSeasonOption = function(offset) {
    let season = this.getSearchOptions().season;
    let year = Number(this.getSearchOptions().year);

    if (!season || isNaN(year)) {
      season = Shows.validQuarters[moment.fromUtc().quarter() - 1];
      year = moment.fromUtc().year();
    } else {
      let seasonIndex = Shows.validQuarters.indexOf(season) + offset;
      year += Math.floor(seasonIndex / 4);
      seasonIndex = seasonIndex.mod(4);
      season = Shows.validQuarters[seasonIndex];
    }

    FlowRouter.withReplaceState(() => {
      FlowRouter.setQueryParams({
        season: season,
        year: year
      });
    });
  };

  // Subscribe to searches based on search options
  this.autorun(() => {
    this.subscribe('searches.withSearch', this.getSearchOptions());
    this.state.set('searchLimit', this.limitIncrement);
  });

  // Search when the subscription is ready
  this.autorun(() => {
    if (this.subscriptionsReady() || Searches.queryWithSearch(this.getSearchOptions()).count()) {
      Tracker.nonreactive(() => {
        Meteor.call('searches.startSearch', this.getSearchOptions());
      });
    }
  });

  // Subscribe to shows based on search options and limit
  this.autorun(() => {
    this.subscribe('shows.search', this.getSearchOptions(), this.state.get('searchLimit'), getStorageItem('SelectedTranslationType'));
  });

  // Subscribe to thumbnails and episodes for all shows
  this.autorun(() => {
    let thumbnailHashes = [];
    Shows.querySearch(this.getSearchOptions(), this.state.get('searchLimit'), getStorageItem('SelectedTranslationType')).forEach((show) => {
      thumbnailHashes = thumbnailHashes.concat(show.thumbnails);
      this.subscribe('episodes.forTranslationType', show._id, getStorageItem('SelectedTranslationType'), 1);
    });
    this.subscribe('thumbnails.withHashes', thumbnailHashes);
  });

  // Set 'LoadingBackground' parameter
  this.autorun(() => {
    Session.set('LoadingBackground', this.isSearching());
  });
});

Template.pages_search.onRendered(function() {
  $('#load-more-results').appear();
});

Template.pages_search.onDestroyed(function() {
  Session.set('LoadingBackground', false);
});

Template.pages_search.helpers({
  shows() {
    return Shows.querySearch(Template.instance().getSearchOptions(), Template.instance().state.get('searchLimit'), getStorageItem('SelectedTranslationType'));
  },

  showsLoading() {
    return !Template.instance().subscriptionsReady() || Template.instance().isSearching() || Template.instance().canLoadMoreShows();
  },

  searchOptions() {
    return Template.instance().getSearchOptions();
  },

  hasLatestEpisode(show) {
    return typeof show.latestEpisode(getStorageItem('SelectedTranslationType')) !== 'undefined';
  },

  latestEpisodeNumbers(show) {
    let latestEpisode = show.latestEpisode(getStorageItem('SelectedTranslationType'));
    return latestEpisode.episodeNumStart
      + (latestEpisode.episodeNumStart !== latestEpisode.episodeNumEnd ? ' - ' + latestEpisode.episodeNumEnd : '');
  },

  latestEpisodeNotes(show) {
    return show.latestEpisode(getStorageItem('SelectedTranslationType')).notes;
  },

  latestEpisodeLink(show) {
    let latestEpisode = show.latestEpisode(getStorageItem('SelectedTranslationType'));
    return FlowRouter.path('episode', {
      showId: latestEpisode.showId,
      translationType: latestEpisode.translationType,
      episodeNumStart: latestEpisode.episodeNumStart,
      episodeNumEnd: latestEpisode.episodeNumEnd,
      notes: latestEpisode.notesEncoded()
    });
  },

  sortDirectionDisabled() {
    return !Template.instance().getSearchOptions().sortBy;
  }
});

Template.pages_search.events({
  'appear #load-more-results'(event) {
    if (Template.instance().subscriptionsReady() && Template.instance().canLoadMoreShows()) {
      Template.instance().state.set('searchLimit', Template.instance().state.get('searchLimit') + Template.instance().limitIncrement);
    }
  },

  'click #animeSearchFormOptionsReset'(event) {
    let reset = {};
    Object.keys(FlowRouter.current().queryParams).forEach((key) => {
      if (!['query', 'sortBy', 'sortDirection'].includes(key)) {
        reset[key] = null;
      }
    });
    FlowRouter.withReplaceState(() => {
      FlowRouter.setQueryParams(reset);
    });
  },

  'click .btn-prev-season'(event) {
    Template.instance().moveSeasonOption(-1);
  },

  'click .btn-next-season'(event) {
    Template.instance().moveSeasonOption(1);
  },

  'click .btn-preset-recent'(event) {
    FlowRouter.setQueryParams({
      sortBy: 'Latest Update',
      sortDirection: -1
    });
  },

  'click .btn-preset-season'(event) {
    FlowRouter.setQueryParams({
      season: Shows.validQuarters[moment.fromUtc().quarter() - 1],
      year: moment.fromUtc().year()
    });
  }
});

AutoForm.hooks({
  animeSearchFormQuery: {
    onSubmit(insertDoc) {
      FlowRouter.withReplaceState(() => {
        FlowRouter.setQueryParams({
          query: insertDoc.query
        });
      });
      this.done();
      return false;
    }
  },

  animeSearchFormSorting: {
    onSubmit(insertDoc) {
      // Remove default parameters
      Object.keys(insertDoc).forEach((key) => {
        if (Schemas.Search._schema[key].defaultValue === insertDoc[key]) {
          insertDoc[key] = null;
        }
      });
      // Remove sort direction for the default sort order
      if (!insertDoc.sortBy) {
        insertDoc.sortDirection = null;
      }

      FlowRouter.withReplaceState(() => {
        FlowRouter.setQueryParams({
          sortBy: insertDoc.sortBy,
          sortDirection: insertDoc.sortDirection
        });
      });
      this.done();
      return false;
    }
  },

  animeSearchFormOptions: {
    onSubmit(insertDoc) {
      // Remove missing parameters
      Object.keys(FlowRouter.current().queryParams).forEach((key) => {
        if (!['query', 'sortBy', 'sortDirection'].includes(key) && !insertDoc.hasOwnProperty(key)) {
          insertDoc[key] = null;
        }
      });
      // Remove default parameters
      Object.keys(insertDoc).forEach((key) => {
        if (Schemas.Search._schema[key].defaultValue === insertDoc[key]) {
          insertDoc[key] = null;
        }
      });

      FlowRouter.withReplaceState(() => {
        FlowRouter.setQueryParams(insertDoc);
      });
      this.done();
      return false;
    }
  }
});
