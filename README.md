## OpenY PEF GXP Sync

Synchronizes GroupEx schedules to PEF.

### Quick start

#### Enable the modules

1. Log in as an Admin
1. Go to Extend (`/admin/modules`)
1. Install `OpenY PEF GXP Sync` and `Open Y Mappings Feature`  then enable all dependencies as requested on the next step.

#### Configure the OpenY GXP module

1. Go to: Open Y -> Integrations -> GroupEx Pro -> GroupEx Pro settings (`/admin/openy/integrations/groupex-pro/gxp`).
1. Set up your GroupExPro Client Id (which you can obtain from GroupEx PRO support).
1. Provide the parent activity ID. It should be listed in Group Exercises, under Fitness.
1. Enter Activity `Group Exercise Classes` (choose node of type Activity from autocomplete). Most likely the ID will be 94 if this is a default demo content).
1. Enter Locations Mapping in the following format:

	```
	202,West YMCA
	204,Downtown YMCA
	203,East YMCA
	3718,South YMCA
	```
	
1. Save the configuration.
1. Go to: Configuration -> System -> YMCA Sync settings (`/admin/config/system/ymca-sync`)
1. Enable the checkbox labeled `openy_pef_gxp_sync` and Save. The `openy_pef_gxp_sync` module should be enabled in your system.
1. Go to: Open Y -> Settings -> Mappings -> Mapping list (`/admin/openy/settings/mappings/mapping`)
1. Add mappings for every branch you would like to synchronize:
	- Enter the name of the mapping to easily identify it in the future. For instance, `West YMCA GXP sync mapping`.
	- Authored by - Keep as is
	- Locations - Choose Branch
	- GroupEx ID - Enter the GroupEx ID of the Branch
	- Save
1. Go to: `admin/openy/settings/groupex-enabled-locations` and enable Locations that you want to sync.
1. Run the Drush command to sync from your project docroot:
	- `drush openy-pef-gxp-sync` (Drupal 8, Drush 8)
	- `drush yn-sync openy_pef_gxp_sync.syncer` (Drupal 9, Drush 10)

#### How to sync my GroupEx data to my project?

See the final step above for the proper Drush commands.

### How the syncer works

The syncer consists of the next steps:

1. Fetcher - fetches data from GroupEx API.
2. Wrapper - processes the data for saving (maps location ids, fixes title encoding problems, etc).
3. Wrapper - groups all items by Class ID and Location ID, calculates hashes.
4. Wrapper - prepares data to be removed (extra items in DB or changed hashes)
5. Wrapper - prepares data to be created (new items + changed hashes)
6. Cleaner - removes data to be removed.
7. Saver   - creates data to be created.

### How the syncer works (for developers)

#### Adding & Removing locations

1. If a location is removed in API it should be removed in DB.
2. If a location is added in API it should be added (with classes) in DB.
3. If a class is removed in API it should be removed in DB (with all class items);
3. If a class is added in API it should be added in DB (with all class items);

#### Updating classes

1. Each GroupEx class can have several class items (with the same class ID).
2. We compare hashes for Location ID + Class ID + all class items inside (on unprocessed data!).
3. If the hash is changed we should remove all items belonging to this hash and create them again.

### How to debug

1. To emulate API data please use `FetcherDebuggerClass`. Just replace `@openy_pef_gxp_sync.fetcher` with
`@openy_pef_gxp_sync.fetcher_debugger` to emulate API response.
2. Use `DEBUG_MODE` constants inside classes to debug specific services.

### Known issues in sync.

1. There is an issue if a class in a GroupEx has its category set to "General" - it will not be synced and displayed at PEF. This is a limitation of GroupEX PRO API.

### Default Syncer behavior

By default, the Syncer creates unpublished Session nodes.
In order for them to become visible in the Schedules application, you'd need to set config variables to allow unpublished entities to be displayed

- config `openy_repeat.settings` - variable `allow_unpublished_references: 1` - this is for unpublished Session, Program, Program Subcategory session nodes.
- config `openy_session_instance.settings` - variable `allow_unpublished_references: 1` - this works only for unpublished Session nodes.

Run next commands if you want to switch to `published mode`:
```
 drush cset openy_repeat.settings allow_unpublished_references 1 -y
 drush cset openy_session_instance.settings allow_unpublished_references 1 -y
```
Run next commands if you want to switch to `unpublished mode`:
```
 drush cset openy_repeat.settings allow_unpublished_references 0 -y
 drush cset openy_session_instance.settings allow_unpublished_references 0 -y
```

You need to clear cache in order to get this setting working.
At this moment we have no UI for setting these variables, so using `drush cset` or importing configs via Config Manager is recommended.

### Enabled Groupex Locations

Use config `openy_pef_gxp_sync.enabled_locations` to allow locations from GroupEx PRO to be synced.

This config contains an array of location IDs from GroupEx.
