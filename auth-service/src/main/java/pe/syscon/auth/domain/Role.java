package pe.syscon.auth.domain;

import jakarta.persistence.*;
import java.util.*;

@Entity
public class Role {
    @Id public String name;
    @ManyToMany(fetch=FetchType.EAGER) public Set<Permission> permissions = new HashSet<>();
    protected Role() {}
    public Role(String name) { this.name=name; }
}
