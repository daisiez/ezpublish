{* Related objects window. *}
<div class="context-block">
<h2 class="context-title">{'Relations'|i18n( 'design/admin/content/view')}</h2>

{* Related objects list. *}
<table class="list" cellspacing="0">
<tr>
    <th>{'Related objects'|i18n( 'design/admin/content/view' )} ({$node.object.related_contentobject_count})</th>
</tr>
{section show=$node.object.related_contentobject_count}
    {section var=RelatedObjects loop=$node.object.related_contentobject_array sequence=array( bglight, bgdark )}
        <tr class="{$RelatedObjects.sequence}">
        <td>{$RelatedObjects.item.content_class.identifier|class_icon( small, $RelatedObjects.item.content_class.name )}&nbsp;{content_view_gui view=text_linked content_object=$RelatedObjects.item}</td>
        </tr>
{/section}
{section-else}
<tr><td>{'The item being viewed does not make use of any other objects.'|i18n( 'design/admin/content/view' )}</td></tr>
{/section}
</table>

{* Reverse related objects list. *}
<table class="list" cellspacing="0">
<tr>
    <th>{'Reverse related objects'|i18n( 'design/admin/content/view' )} ({$node.object.reverse_related_contentobject_count})</th>
</tr>
{section show=$node.object.reverse_related_contentobject_count}
    {section var=ReverseRelatedObjects loop=$node.object.reverse_related_contentobject_array sequence=array( bglight, bgdark )}
        <tr class="{$ReverseRelatedObjects.sequence}">
        <td>{$ReverseRelatedObjects.content_class.identifier|class_icon( small, $ReverseRelatedObjects.item.content_class.name )}&nbsp;{content_view_gui view=text_linked content_object=$ReverseRelatedObjects.item}</td>
        </tr>
    {/section}
{section-else}
<tr><td>{'The item being viewed is not in use by any other objects.'|i18n( 'design/admin/content/view' )}</td></tr>
{/section}
</table>
</div>
