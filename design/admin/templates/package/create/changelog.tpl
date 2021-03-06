{* DO NOT EDIT THIS FILE! Use an override template instead. *}
{let current_user=fetch( user, current_user )}
<div id="package" class="create">
    <div id="sid-{$current_step.id|wash}" class="pc-{$creator.id|wash}">

    <form method="post" action={'package/create'|ezurl}>

    {include uri="design:package/create/error.tpl"}

    {include uri="design:package/header.tpl"}

    <p>{'Please provide information on the changes.'|i18n('design/admin/package')}</p>

    <div class="block">
        <label>{'Name'|i18n('design/admin/package')}</label>
        <input class="box" type="text" name="PackageChangelogPerson" value="{$persistent_data.changelog_person|wash}" />
    </div>

    <div class="block">
        <label>{'Email'|i18n('design/admin/package')}</label>
        <input class="box" type="text" name="PackageChangelogEmail" value="{$persistent_data.changelog_email|wash}" />
    </div>

    <div class="block">
        <label>{'Changes'|i18n('design/admin/package')}</label>
        <p>{'Start an entry with a marker ( %emstart-%emend (dash) or %emstart*%emend (asterisk) ) at the beginning of the line. The change will continue to the next change marker.'|i18n( 'design/admin/package',, hash( '%emstart', '<em>', '%emend', '</em>' ) )|break}</p>
        <textarea class="box" rows="10" name="PackageChangelogText">{$persistent_data.changelog_text|wash}</textarea>
    </div>

    {include uri="design:package/navigator.tpl"}

    </form>

    </div>
</div>
{/let}
