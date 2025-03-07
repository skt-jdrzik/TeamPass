<?php

namespace TeampassClasses\NestedTree;

/**
 * Teampass - a collaborative passwords manager.
 * ---
 * This file is part of the TeamPass project.
 * 
 * TeamPass is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 * 
 * TeamPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 * 
 * Certain components of this file may be under different licenses. For
 * details, see the `licenses` directory or individual file headers.
 * ---
 * @file      NestedTree.php
 * @author    Nils Laumaillé (nils@teampass.net)
 * @copyright 2009-2025 Teampass.net
 * @license   GPL-3.0
 * @see       https://www.teampass.net
 */

class NestedTree
{
    private $table;
    private $fields;
    private $link;

    /**
     * Constructor. Set the database table name and necessary field names.
     *
     * @param string $table       Name of the tree database table
     * @param string $idField     Name of the primary key ID field
     * @param string $parentField Name of the parent ID field
     * @param string $sortField   name of the field to sort data
     */
    public function __construct($table, $idField, $parentField, $sortField)
    {
        $this->table = $table;

        $this->fields = array(
            'id' => $idField,
            'parent' => $parentField,
            'sort' => $sortField,
        );

        $this->link = mysqli_connect(DB_HOST, DB_USER, DB_PASSWD_CLEAR, DB_NAME, (int) DB_PORT, null);
        $this->link->set_charset(DB_ENCODING);
    }

    /**
     * A utility function to return an array of the fields
     * that need to be selected in SQL select queries.
     *
     * @return array An indexed array of fields to select
     */
    public function getFields()
    {
        return array(
            $this->fields['id'],
            $this->fields['parent'],
            $this->fields['sort'],
            'nleft', 'nright', 'nlevel', 'personal_folder', 'renewal_period', 'bloquer_modification', 'bloquer_creation', 'fa_icon', 'fa_icon_selected', 'nb_items_in_folder', 'nb_subfolders', 'nb_items_in_subfolders');
    }

    /**
     * Fetch the node data for the node identified by $folder_id.
     *
     * @param int $folder_id The ID of the node to fetch
     *
     * @return object An object containing the node's
     *                data, or null if node not found
     */
    public function getNode($folder_id)
    {
        $query = sprintf(
            'select %s from %s where %s = %d',
            join(',', $this->getFields()),
            $this->table,
            $this->fields['id'],
            mysqli_real_escape_string($this->link, $folder_id)
        );

        $result = mysqli_query($this->link, $query);
        // Exclude case where result is empty
        if ($result !== false) {
            if ($row = mysqli_fetch_object($result)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Fetch the descendants of a node, or if no node is specified, fetch the
     * entire tree. Optionally, only return child data instead of all descendant
     * data.
     *
     * @param int  $folder_id    The ID of the node to fetch descendant data for.
     *                           Specify an invalid ID (e.g. 0) to retrieve all data.
     * @param bool $includeSelf  Whether or not to include the passed node in the
     *                           the results. This has no meaning if fetching entire tree.
     * @param bool $childrenOnly True if only returning children data. False if
     *                           returning all descendant data
     *
     * @return array The descendants of the passed now
     */
    public function getDescendants($folder_id = 0, $includeSelf = false, $childrenOnly = false, $unique_id_list = false)
    {
        $idField = $this->fields['id'];

        $node = $this->getNode(filter_var($folder_id, FILTER_SANITIZE_NUMBER_INT));
        if (is_null($node)) {
            $nleft = 0;
            $nright = 0;
            $parent_id = 0;
        } else {
            $nleft = $node->nleft;
            $nright = $node->nright;
            $parent_id = $node->$idField;
        }

        if ($childrenOnly) {
            if ($includeSelf) {
                $query = sprintf(
                    'select %s from %s where %s = %d or %s = %d order by nleft',
                    join(',', $this->getFields()),
                    $this->table,
                    $this->fields['id'],
                    $parent_id,
                    $this->fields['parent'],
                    $parent_id
                );
            } else {
                $query = sprintf(
                    'select %s from %s where %s = %d order by nleft',
                    join(',', $this->getFields()),
                    $this->table,
                    $this->fields['parent'],
                    $parent_id
                );
            }
        } else {
            if ($nleft > 0 && $includeSelf) {
                $query = sprintf(
                    'select %s from %s where nleft >= %d and nright <= %d order by nleft',
                    join(',', $this->getFields()),
                    $this->table,
                    $nleft,
                    $nright
                );
            } elseif ($nleft > 0) {
                $query = sprintf(
                    'select %s from %s where nleft > %d and nright < %d order by nleft',
                    join(',', $this->getFields()),
                    $this->table,
                    $nleft,
                    $nright
                );
            } else {
                $query = sprintf(
                    'select %s from %s order by nleft',
                    join(',', $this->getFields()),
                    $this->table
                );
            }
        }

        $result = mysqli_query($this->link, $query);

        $arr = array();
        while ($row = mysqli_fetch_object($result)) {
            if ($unique_id_list === false) {
                $arr[$row->$idField] = $row;
            } else {
                array_push($arr, $row->$idField);
            }
        }

        return $arr;
    }

    /**
     * Costless than getDescendants, need a tree from $this->getDescendants().
     * $this->getDescendantsFromTreeArray($treeAray, 10);
     * Gives the same results as $this->getDescendants(10, true, false, true);
     * Without any sql call (useful in loops on very big teampass instances).
     *
     * @param int  $treeAray $this->getDescendants() array
     * @param bool $parentId ID of the parent node.
     *
     * @return array The children of the passed node
     */
    public function getDescendantsFromTreeArray(&$treeAray, $parentId, $firstIteration = true) {
        $descendants = $firstIteration ? [$parentId] : [];
    
        foreach ($treeAray as $key => $object) {
            // If the object's parent_id matches parentId, it is added to the list of descendants
            if ($object->parent_id == $parentId) {
                // Ajouter l'id du descendant
                $descendants[] = $object->id;
    
                // If nb_subfolders > 0, recursive call to find the descendants of this element
                if ($object->nb_subfolders > 0) {
                    $descendants = array_merge($descendants, $this->getDescendantsFromTreeArray($treeAray, $object->id, false));
                }
            }
        }
    
        return $descendants;
    }

    /**
     * Fetch the children of a node, or if no node is specified, fetch the
     * top level items.
     *
     * @param int  $id          the ID of the node to fetch child data for
     * @param bool $includeSelf whether or not to include the passed node in the
     *                          the results
     *
     * @return array The children of the passed node
     */
    public function getChildren($folder_id = 0, $includeSelf = false)
    {
        return $this->getDescendants($folder_id, $includeSelf, true);
    }

    /**
     * Fetch the path to a node. If an invalid node is passed, an empty array is returned.
     * If a top level node is passed, an array containing on that node is included (if
     * 'includeSelf' is set to true, otherwise an empty array).
     *
     * @param int  $folder_id   the ID of the node to fetch child data for
     * @param bool $includeSelf whether or not to include the passed node in the
     *                          the results
     *
     * @return array An array of each node to passed node
     */
    public function getPath($folder_id = 0, $includeSelf = false)
    {
        $node = $this->getNode($folder_id);
        if (is_null($node)) {
            return array();
        }

        if ($includeSelf) {
            $query = sprintf(
                'select %s from %s where nleft <= %d and nright >= %d order by nlevel',
                join(',', $this->getFields()),
                $this->table,
                $node->nleft,
                $node->nright
            );
        } else {
            $query = sprintf(
                'select %s from %s where nleft < %d and nright > %d order by nlevel',
                join(',', $this->getFields()),
                $this->table,
                $node->nleft,
                $node->nright
            );
        }

        $result = mysqli_query($this->link, $query);

        $idField = $this->fields['id'];
        $arr = array();
        while ($row = mysqli_fetch_object($result)) {
            $arr[$row->$idField] = $row;
        }

        return $arr;
    }

    /**
     * Check if one node descends from another node. If either node is not
     * found, then false is returned.
     *
     * @param int $descendant_id The node that potentially descends
     * @param int $ancestor_id   The node that is potentially descended from
     *
     * @return bool True if $descendant_id descends from $ancestor_id, false otherwise
     */
    public function isDescendantOf($descendant_id, $ancestor_id)
    {
        $node = $this->getNode($ancestor_id);
        if (is_null($node)) {
            return false;
        }

        $query = sprintf(
            'select count(*) as is_descendant
            from %s
            where %s = %d
            and nleft > %d
            and nright < %d',
            $this->table,
            $this->fields['id'],
            mysqli_real_escape_string($this->link, $descendant_id),
            $node->nleft,
            $node->nright
        );

        $result = mysqli_query($this->link, $query);

        if ($row = mysqli_fetch_object($result)) {
            return $row->is_descendant > 0;
        }

        return false;
    }

    /**
     * Check if one node is a child of another node. If either node is not
     * found, then false is returned.
     *
     * @param int $child_id  The node that is possibly a child
     * @param int $parent_id The node that is possibly a parent
     *
     * @return bool True if $child_id is a child of $parent_id, false otherwise
     */
    public function isChildOf($child_id, $parent_id)
    {
        $query = sprintf(
            'select count(*) as is_child from %s where %s = %d and %s = %d',
            $this->table,
            $this->fields['id'],
            mysqli_real_escape_string($this->link, $child_id),
            $this->fields['parent'],
            mysqli_real_escape_string($this->link, $parent_id)
        );

        $result = mysqli_query($this->link, $query);

        if ($row = mysqli_fetch_object($result)) {
            return $row->is_child > 0;
        }

        return false;
    }

    /**
     * Find the number of descendants a node has.
     *
     * @param int $folder_id The ID of the node to search for. Pass 0 to count all nodes in the tree.
     *
     * @return int the number of descendants the node has, or -1 if the node isn't found
     */
    public function numDescendants($folder_id)
    {
        if ($folder_id == 0) {
            $query = sprintf('select count(*) as num_descendants from %s', $this->table);
            $result = mysqli_query($this->link, $query);
            if ($row = mysqli_fetch_object($result)) {
                return (int) $row->num_descendants;
            }
        } else {
            $node = $this->getNode($folder_id);
            if (!is_null($node)) {
                return ($node->nright - $node->nleft - 1) / 2;
            }
        }

        return -1;
    }

    /**
     * Find the number of children a node has.
     *
     * @param int $folder_id The ID of the node to search for. Pass 0 to count the first level items
     *
     * @return int the number of descendants the node has, or -1 if the node isn't found
     */
    public function numChildren($folder_id)
    {
        $query = sprintf(
            'select count(*) as num_children from %s where %s = %d',
            $this->table,
            $this->fields['parent'],
            mysqli_real_escape_string($this->link, $folder_id)
        );
        $result = mysqli_query($this->link, $query);
        if ($row = mysqli_fetch_object($result)) {
            return (int) $row->num_children;
        }

        return -1;
    }

    /**
     * Fetch the tree data, nesting within each node references to the node's children.
     *
     * @return array The tree with the node's child data
     */
    public function getTreeWithChildren()
    {
        $idField = $this->fields['id'];
        $parentField = $this->fields['parent'];

        $query = sprintf(
            'select %s from %s order by %s',
            join(',', $this->getFields()),
            $this->table,
            $this->fields['sort']
        );

        $result = mysqli_query($this->link, $query);

        // create a root node to hold child data about first level items
        $root = new \stdClass();
        $root->$idField = 0;
        $root->children = array();

        $arr = array($root);

        // Exclude case where result is empty
        if ($result !== false) {
            // populate the array and create an empty children array
            while ($row = mysqli_fetch_object($result)) {
                $arr[$row->$idField] = $row;
                $arr[$row->$idField]->children = array();
            }

            // now process the array and build the child data
            foreach ($arr as $folder_id => $row) {
                if (isset($row->$parentField) && is_null($folder_id) === false && $folder_id >= 0) {
                    $arr[$row->$parentField]->children[$folder_id] = $folder_id;
                }
            }
        }

        return $arr;
    }

    /**
     * Rebuilds the tree data and saves it to the database.
     */
    public function rebuild()
    {
        $data = $this->getTreeWithChildren();

        $n_tally = 0; // need a variable to hold the running n tally

        // invoke the recursive function. Start it processing
        // on the fake "root node" generated in getTreeWithChildren().
        // because this node doesn't really exist in the database, we
        // give it an initial nleft value of 0 and an nlevel of 0.
        $this->generateTreeData($data, 0, 0, $n_tally);

        // Get current nlevel, nright and nleft
        $folder_ids_str = implode(',', array_map('intval', array_keys($data)));
        $query = "SELECT id, nlevel, nright, nleft 
                  FROM " . $this->table . "
                  WHERE id IN(" . $folder_ids_str . ")";
        $result = mysqli_query($this->link, $query);

        // Array with folders current nlevel, nright and nleft values.
        $folders_infos = [];
        while ($result && $row = mysqli_fetch_assoc($result))
            $folders_infos[$row['id']] = $row;

        // at this point the the root node will have nleft of 0, nlevel of 0
        // and nright of (tree size * 2 + 1)

        foreach ($data as $folder_id => $row) {
            // skip the root node
            if ($folder_id == 0
                || isset($row->nlevel) === false
                || isset($row->nleft) === false
                || isset($row->nright) === false
            ) {
                continue;
            }

            // Don't update if no change (better performances)
            if (!empty($folders_infos[$folder_id])
                && (int) $row->nlevel === (int) $folders_infos[$folder_id]['nlevel']
                && (int) $row->nleft  === (int) $folders_infos[$folder_id]['nleft']
                && (int) $row->nright === (int) $folders_infos[$folder_id]['nright']
            ) {
                continue;
            }

            $query = sprintf(
                'update %s set nlevel = %d, nleft = %d, nright = %d where %s = %d',
                $this->table,
                $row->nlevel,
                $row->nleft,
                $row->nright,
                $this->fields['id'],
                $folder_id
            );
            mysqli_query($this->link, $query);
        }
    }

    /**
     * Generate the tree data. A single call to this generates the n-values for
     * 1 node in the tree. This function assigns the passed in n value as the
     * node's nleft value. It then processes all the node's children (which
     * in turn recursively processes that node's children and so on), and when
     * it is finally done, it takes the update n-value and assigns it as its
     * nright value. Because it is passed as a reference, the subsequent changes
     * in subrequests are held over to when control is returned so the nright
     * can be assigned.
     *
     * @param array &$arr      A reference to the data array, since we need to
     *                         be able to update the data in it
     * @param int   $folder_id The ID of the current node to process
     * @param int   $fld_level The nlevel to assign to the current node
     * @param int   $n_tally   A reference to the running tally for the n-value
     * @param int   $n
     */
    public function generateTreeData(&$arr, $folder_id, $fld_level, &$n_tally)
    {
        $arr[$folder_id]->nlevel = $fld_level;
        $arr[$folder_id]->nleft = $n_tally++;

        // loop over the node's children and process their data
        // before assigning the nright value
        foreach ($arr[$folder_id]->children as $child_id) {
            $this->generateTreeData($arr, $child_id, $fld_level + 1, $n_tally);
        }
        $arr[$folder_id]->nright = $n_tally++;
    }

    /**
     * Fetch the immediately family of a node. More specifically, fetch a node's
     * parent, siblings and children. If the node isn't valid, fetch the first
     * level of nodes from the tree.
     *
     * @param int $folder_id the ID of the node to fetch child data for
     *
     * @return array An array of each node in the family
     */
    public function getImmediateFamily($folder_id)
    {
        $node = $this->getNode($folder_id);
        $idField = $this->fields['id'];
        $parentField = $this->fields['parent'];

        if ($node->$idField > 0) {
            // the passed node was valid, get the family
            $query = sprintf(
                'select %s from %s where %s = %s or %s = %s or %s = %s order by nleft',
                join(',', $this->getFields()),
                $this->table,
                $idField,
                $node->$parentField,
                $parentField,
                $node->$parentField,
                $parentField,
                $node->$idField
            );
        } else {
            // the passed node did not exist, get the first level of nodes
            $query = sprintf(
                'select %s from %s where %s = 0 order by nleft',
                join(',', $this->getFields()),
                $this->table,
                $parentField
            );
        }

        $result = mysqli_query($this->link, $query);

        $arr = array();
        while ($row = mysqli_fetch_object($result)) {
            $row->num_descendants = ($row->nright - $row->nleft - 1) / 2;
            $arr[$row->$idField] = $row;
        }

        return $arr;
    }
}
