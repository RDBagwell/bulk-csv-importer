import { Link } from '@inertiajs/react';
import { BookOpen, FileUp, FolderGit2, LayoutGrid } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { create as newImport, index as imports } from '@/routes/imports';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Imports',
        href: imports(),
        icon: LayoutGrid,
    },
    {
        title: 'New import',
        href: newImport(),
        icon: FileUp,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Source code',
        href: 'https://github.com/RDBagwell/bulk-csv-importer',
        icon: FolderGit2,
    },
    {
        title: 'How it works',
        href: 'https://github.com/RDBagwell/bulk-csv-importer#readme',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={imports()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
